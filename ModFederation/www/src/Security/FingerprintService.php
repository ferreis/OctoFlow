<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Serviço para gerar e validar fingerprints de contexto (User-Agent + IP).
 *
 * O fingerprint é um hash que representa o contexto único do cliente (navegador + localização).
 * Utilizado para detectar mudanças suspeitas de contexto durante renovação de tokens.
 */
class FingerprintService
{
    /**
     * Gera o fingerprint hash baseado no contexto da requisição.
     *
     * Combina User-Agent e endereço IP do cliente para criar uma
     * impressão digital única daquele cliente específico.
     *
     * @return string Hash SHA-256 do fingerprint concatenado
     */
    public function generate(Request $request): string
    {
        $userAgent = $this->extractUserAgent($request);
        $ipAddress = $this->extractClientIp($request);

        // Concatena User-Agent + IP e gera hash único
        return hash('sha256', $userAgent . '|' . $ipAddress);
    }

    /**
     * Gera hash separado do User-Agent para análise individual.
     *
     * @return string Hash SHA-256 do User-Agent
     */
    public function generateUserAgentHash(Request $request): string
    {
        $userAgent = $this->extractUserAgent($request);
        return hash('sha256', $userAgent);
    }

    /**
     * Gera hash separado do IP para análise individual.
     *
     * @return string Hash SHA-256 do IP
     */
    public function generateIpHash(Request $request): string
    {
        $ipAddress = $this->extractClientIp($request);
        return hash('sha256', $ipAddress);
    }

    /**
     * Valida se o fingerprint atual é compatível com o armazenado.
     *
     * Permite detecção de mudanças radicais de contexto que podem indicar
     * comprometimento da sessão ou movimento geográfico suspeito.
     *
     * @param string $storedFingerprintHash Hash armazenado no banco de dados
     * @param string $currentFingerprintHash Hash gerado na requisição atual
     * @return bool True se os fingerprints combinam (mesmo contexto)
     */
    public function validate(string $storedFingerprintHash, string $currentFingerprintHash): bool
    {
        return hash_equals($storedFingerprintHash, $currentFingerprintHash);
    }

    /**
     * Valida o User-Agent isoladamente.
     *
     * Útil para detectar se apenas o navegador/cliente mudou,
     * mantendo o mesmo IP (ex: novo device na mesma rede).
     *
     * @param string $storedUserAgentHash Hash do User-Agent armazenado
     * @param string $currentUserAgentHash Hash do User-Agent atual
     * @return bool True se User-Agents combinam
     */
    public function validateUserAgent(string $storedUserAgentHash, string $currentUserAgentHash): bool
    {
        return hash_equals($storedUserAgentHash, $currentUserAgentHash);
    }

    /**
     * Valida o IP isoladamente.
     *
     * Detect se a requisição vem de um IP diferente do armazenado.
     * Pode indicar mudança de localização ou IP compartilhado.
     *
     * @param string $storedIpHash Hash do IP armazenado
     * @param string $currentIpHash Hash do IP atual
     * @return bool True se IPs combinam
     */
    public function validateIp(string $storedIpHash, string $currentIpHash): bool
    {
        return hash_equals($storedIpHash, $currentIpHash);
    }

    /**
     * Determina o nível de mudança de contexto.
     *
     * Retorna uma classificação sobre o quão drástica foi a mudança:
     * - NONE: Contexto idêntico
     * - LOW: Apenas User-Agent mudou (device novo na mesma rede)
     * - MEDIUM: Apenas IP mudou (acesso de novo local com mesmo device)
     * - HIGH: Ambos mudaram (contexto completamente diferente)
     *
     * @return string Uma das constantes: 'none', 'low', 'medium' ou 'high'
     */
    public function assessContextChange(
        string $storedUserAgentHash,
        string $currentUserAgentHash,
        string $storedIpHash,
        string $currentIpHash
    ): string {
        $userAgentMatch = hash_equals($storedUserAgentHash, $currentUserAgentHash);
        $ipMatch = hash_equals($storedIpHash, $currentIpHash);

        if ($userAgentMatch && $ipMatch) {
            return 'none';
        }

        if (!$userAgentMatch && $ipMatch) {
            return 'low';
        }

        if ($userAgentMatch && !$ipMatch) {
            return 'medium';
        }

        // Ambos diferentes
        return 'high';
    }

    /**
     * Extrai o User-Agent da requisição HTTP.
     *
     * Normaliza o User-Agent removendo espaços extras para
     * garantir comparações consistentes.
     *
     * @return string User-Agent limpo ou string vazia se não disponível
     */
    private function extractUserAgent(Request $request): string
    {
        $userAgent = $request->headers->get('User-Agent', '');
        return trim($userAgent);
    }

    /**
     * Extrai o endereço IP do cliente com prioridade correta.
     *
     * Considera:
     * 1. X-Forwarded-For (proxies, load balancers)
     * 2. X-Real-IP (nginx reverso proxy)
     * 3. REMOTE_ADDR (IP direto)
     *
     * @return string Endereço IP válido ou 'unknown' se não conseguir extrair
     */
    private function extractClientIp(Request $request): string
    {
        // Tenta X-Forwarded-For em primeiro lugar (usado por load balancers)
        if ($request->headers->has('X-Forwarded-For')) {
            $ips = explode(',', $request->headers->get('X-Forwarded-For', ''));
            $ip = trim($ips[0] ?? '');
            if ($ip !== '') {
                return $ip;
            }
        }

        // Tenta X-Real-IP (nginx reverso proxy)
        if ($request->headers->has('X-Real-IP')) {
            $ip = trim($request->headers->get('X-Real-IP', ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        // Usa o IP direto da requisição
        $ip = $request->getClientIp();
        return $ip !== null ? $ip : 'unknown';
    }
}
