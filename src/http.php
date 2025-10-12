<?php
declare(strict_types=1);

if (!function_exists('oddsapi_request')) {
    /**
     * Perform a GET request against TheOddsAPI (or compatible) endpoint.
     *
     * @return array{int, string|null, array<string,string>, string|null}
     */
    function oddsapi_request(string $url, string $context, array $options = []): array
    {
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 30;
        unset($options['timeout']);

        $ch = curl_init($url);
        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
        ];
        foreach ($options as $opt => $value) {
            $defaultOptions[$opt] = $value;
        }
        curl_setopt_array($ch, $defaultOptions);

        $raw = curl_exec($ch);
        $err = curl_error($ch) ?: null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if (!is_string($raw)) {
            log_request_cost($context, [], $code);
            return [$code, null, [], $err ?: 'curl_exec failed'];
        }

        if ($headerSize > 0) {
            $headersRaw = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
        } else {
            $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
            $headersRaw = $parts[0] ?? '';
            $body = $parts[1] ?? '';
        }

        $headerBlocks = preg_split("/\r?\n\r?\n/", trim((string)$headersRaw)) ?: [];
        $lastBlock = end($headerBlocks);
        if (!is_string($lastBlock)) {
            $lastBlock = (string)$headersRaw;
        }

        $headers = [];
        foreach (preg_split("/\r?\n/", trim($lastBlock)) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        log_request_cost($context, $headers, $code);
        return [$code, $body, $headers, $err];
    }
}

if (!function_exists('log_request_cost')) {
    /**
     * Print the remaining/used request counters supplied by TheOddsAPI.
     */
    function log_request_cost(string $context, array $headers, int $code = 0): void
    {
        $used = $headers['x-requests-used']
            ?? $headers['requests-used']
            ?? $headers['x-requests']
            ?? null;
        $remaining = $headers['x-requests-remaining']
            ?? $headers['requests-remaining']
            ?? null;

        if ($used !== null || $remaining !== null) {
            echo sprintf(
                "[cost:%s] used=%s remaining=%s (HTTP %d)\n",
                $context,
                $used ?? 'n/a',
                $remaining ?? 'n/a',
                $code
            );
            return;
        }

        echo sprintf("[cost:%s] usage headers unavailable (HTTP %d)\n", $context, $code);
    }
}
