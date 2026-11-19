<?php
/**
 * Client minimal pour l'API GraphQL de Railway (backboard).
 *
 * Reference : https://docs.railway.com/reference/public-api
 * Schema verifie le 19 novembre 2026 (railwayapp 3.8.2) :
 *   query    variables(environmentId!, projectId!, serviceId, unrendered) : ServiceVariables
 *   mutation variableUpsert(input: {environmentId!, name!, projectId!, serviceId, value!}) : Boolean
 *   mutation variableDelete(input: {environmentId!, name!, projectId!, serviceId}) : Boolean
 *   mutation serviceInstanceRedeploy(environmentId!, serviceId!) : Boolean
 *
 * Jeton : un jeton de PROJET (en-tete Project-Access-Token, defaut) ou un
 * jeton de COMPTE (en-tete Authorization: Bearer) — variable
 * RAILWAY_TOKEN_TYPE = project | account.
 */

final class RailwayException extends Exception
{
}

final class RailwayClient
{
    private string $url;
    private string $token;
    private string $tokenType;
    private string $projectId;
    private string $environmentId;
    private string $serviceId;

    public function __construct(array $cfg)
    {
        $this->url = $cfg['url'];
        $this->token = $cfg['token'];
        $this->tokenType = $cfg['tokenType'];
        $this->projectId = $cfg['projectId'];
        $this->environmentId = $cfg['environmentId'];
        $this->serviceId = $cfg['serviceId'];
    }

    /** Construit le client depuis les variables d'environnement, ou null si le controle n'est pas configure. */
    public static function fromEnv(): ?self
    {
        $token = getenv('RAILWAY_API_TOKEN') ?: '';
        $projectId = getenv('RAILWAY_PROJECT_ID') ?: '';
        $environmentId = getenv('RAILWAY_ENVIRONMENT_ID') ?: '';
        $serviceId = getenv('LB_SERVICE_ID') ?: '';
        if ($token === '' || $projectId === '' || $environmentId === '' || $serviceId === '') {
            return null;
        }
        return new self([
            'url' => getenv('RAILWAY_API_URL') ?: 'https://backboard.railway.com/graphql/v2',
            'token' => $token,
            'tokenType' => (getenv('RAILWAY_TOKEN_TYPE') ?: 'project') === 'account' ? 'account' : 'project',
            'projectId' => $projectId,
            'environmentId' => $environmentId,
            'serviceId' => $serviceId,
        ]);
    }

    private function graphql(string $query, array $variables): array
    {
        $headers = ['Content-Type: application/json', 'User-Agent: extractor-dashboard/1.0'];
        $headers[] = $this->tokenType === 'account'
            ? 'Authorization: Bearer ' . $this->token
            : 'Project-Access-Token: ' . $this->token;

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RailwayException('API Railway injoignable : ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if ($code !== 200) {
            throw new RailwayException('API Railway, HTTP ' . $code . ' : ' . substr((string) $raw, 0, 200));
        }
        if (!is_array($data)) {
            throw new RailwayException('API Railway : reponse illisible.');
        }
        if (!empty($data['errors'])) {
            throw new RailwayException('API Railway : ' . ($data['errors'][0]['message'] ?? 'erreur inconnue'));
        }
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /** Variables du service LB, sous forme de tableau nom => valeur. */
    public function getVariables(): array
    {
        $q = 'query($projectId: String!, $environmentId: String!, $serviceId: String) '
            . '{ variables(projectId: $projectId, environmentId: $environmentId, serviceId: $serviceId) }';
        $d = $this->graphql($q, [
            'projectId' => $this->projectId,
            'environmentId' => $this->environmentId,
            'serviceId' => $this->serviceId,
        ]);
        return is_array($d['variables'] ?? null) ? $d['variables'] : [];
    }

    public function upsert(string $name, string $value): void
    {
        $q = 'mutation($input: VariableUpsertInput!) { variableUpsert(input: $input) }';
        $this->graphql($q, ['input' => [
            'projectId' => $this->projectId,
            'environmentId' => $this->environmentId,
            'serviceId' => $this->serviceId,
            'name' => $name,
            'value' => $value,
        ]]);
    }

    public function delete(string $name): void
    {
        $q = 'mutation($input: VariableDeleteInput!) { variableDelete(input: $input) }';
        $this->graphql($q, ['input' => [
            'projectId' => $this->projectId,
            'environmentId' => $this->environmentId,
            'serviceId' => $this->serviceId,
            'name' => $name,
        ]]);
    }

    public function redeploy(): void
    {
        $q = 'mutation($environmentId: String!, $serviceId: String!) '
            . '{ serviceInstanceRedeploy(environmentId: $environmentId, serviceId: $serviceId) }';
        $this->graphql($q, [
            'environmentId' => $this->environmentId,
            'serviceId' => $this->serviceId,
        ]);
    }

    /** Etat actuel du load balancer, lu depuis les variables du service LB. */
    public function lbState(): array
    {
        return parseLbState($this->getVariables());
    }

    /**
     * Active le load balancer avec les backends choisis, puis redeploie.
     * L'ancien format BACKEND_1, BACKEND_2... est supprime au passage,
     * pour garder BACKENDS comme seule source de verite.
     */
    public function setBackends(array $urls): void
    {
        $this->upsert('BACKENDS', implode(' ', $urls));
        foreach (array_keys($this->getVariables()) as $name) {
            if (preg_match('/^BACKEND_[0-9]+$/', (string) $name)) {
                usleep(300000); // Railway limite les changements rapides de variables
                $this->delete((string) $name);
            }
        }
        usleep(300000);
        $this->redeploy();
    }

    /** Desactive le load balancer (supprime BACKENDS et l'ancien format), puis redeploie. */
    public function disable(): void
    {
        $first = true;
        foreach (array_keys($this->getVariables()) as $name) {
            $name = (string) $name;
            if ($name === 'BACKENDS' || preg_match('/^BACKEND_[0-9]+$/', $name)) {
                if (!$first) {
                    usleep(300000);
                }
                $this->delete($name);
                $first = false;
            }
        }
        usleep(300000);
        $this->redeploy();
    }
}

/**
 * Deduit l'etat du load balancer des variables du service LB :
 * BACKENDS (nouveau format) d'abord, puis BACKEND_1, BACKEND_2... (ancien).
 */
function parseLbState(array $vars): array
{
    $raw = trim((string) ($vars['BACKENDS'] ?? ''));
    if ($raw !== '') {
        $list = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return ['active' => true, 'backends' => array_values($list), 'format' => 'BACKENDS'];
    }
    $legacy = [];
    foreach ($vars as $name => $value) {
        if (preg_match('/^BACKEND_([0-9]+)$/', (string) $name, $m) && trim((string) $value) !== '') {
            $legacy[(int) $m[1]] = trim((string) $value);
        }
    }
    if ($legacy !== []) {
        ksort($legacy);
        return ['active' => true, 'backends' => array_values($legacy), 'format' => 'BACKEND_n'];
    }
    return ['active' => false, 'backends' => [], 'format' => null];
}
