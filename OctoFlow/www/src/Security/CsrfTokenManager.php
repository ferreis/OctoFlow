<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class CsrfTokenManager
{
    private const SESSION_CHALLENGES_KEY = '_custom_csrf_challenges';
    private const SESSION_VISITOR_KEY = '_custom_csrf_visitor';
    private const MAX_TOKEN_TTL_SECONDS = 600;

    public function __construct(
        #[Autowire('%env(string:AUTH_CSRF_HEADER_NAME)%')]
        private readonly string $headerName,
        #[Autowire('%env(string:AUTH_CSRF_ACTION_HEADER_NAME)%')]
        private readonly string $actionHeaderName,
        #[Autowire('%env(int:AUTH_CSRF_TOKEN_TTL)%')]
        private readonly int $tokenTtl,
        #[Autowire('%env(string:AUTH_REFRESH_TOKEN_COOKIE_NAME)%')]
        private readonly string $refreshCookieName,
    ) {
    }

    /**
     * @return array{csrfToken: string, headerName: string, actionHeaderName: string, actionId: string, method: string, path: string, expiresIn: int}
     */
    public function issueChallenge(Request $request, string $method, string $path, string $actionId): array
    {
        $session = $this->getSession($request);
        $this->cleanupExpiredChallenges($session);

        $challengeId = bin2hex(random_bytes(16));
        $plainToken = bin2hex(random_bytes(32));
        $normalizedMethod = $this->normalizeMethod($method);
        $normalizedPath = $this->normalizePath($path);
        $normalizedActionId = $this->normalizeActionId($actionId);
        $subject = $this->resolveSubject($request);

        $challenges = $session->get(self::SESSION_CHALLENGES_KEY, []);
        $challenges = $this->invalidatePreviousChallengesForAction($challenges, $subject, $normalizedActionId);

        $challenges[$challengeId] = [
            'tokenHash' => hash('sha256', $plainToken),
            'subject' => $subject,
            'method' => $normalizedMethod,
            'path' => $normalizedPath,
            'actionId' => $normalizedActionId,
            'expiresAt' => time() + $this->normalizeTokenTtl(),
        ];

        $session->set(self::SESSION_CHALLENGES_KEY, $challenges);

        return [
            'csrfToken' => sprintf('%s.%s', $challengeId, $plainToken),
            'headerName' => $this->headerName,
            'actionHeaderName' => $this->actionHeaderName,
            'actionId' => $normalizedActionId,
            'method' => $normalizedMethod,
            'path' => $normalizedPath,
            'expiresIn' => $this->normalizeTokenTtl(),
        ];
    }

    public function isValidRequest(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $headerToken = trim((string) $request->headers->get($this->headerName, ''));
        $actionId = trim((string) $request->headers->get($this->actionHeaderName, ''));

        if (!$this->isValidCompositeTokenFormat($headerToken) || $actionId === '') {
            return false;
        }

        [$challengeId, $plainToken] = explode('.', $headerToken, 2);

        $session = $request->getSession();
        $challenges = $session->get(self::SESSION_CHALLENGES_KEY, []);
        $challenge = $challenges[$challengeId] ?? null;

        if (!is_array($challenge)) {
            $this->cleanupExpiredChallenges($session);

            return false;
        }

        unset($challenges[$challengeId]);
        $session->set(self::SESSION_CHALLENGES_KEY, $challenges);

        if (!$this->isValidStoredChallenge($challenge, $request, $plainToken, $actionId)) {
            return false;
        }

        $this->cleanupExpiredChallenges($session);

        return true;
    }

    public function getHeaderName(): string
    {
        return $this->headerName;
    }

    public function getActionHeaderName(): string
    {
        return $this->actionHeaderName;
    }

    private function isValidStoredChallenge(array $challenge, Request $request, string $plainToken, string $actionId): bool
    {
        if (($challenge['expiresAt'] ?? 0) < time()) {
            return false;
        }

        $tokenHash = $challenge['tokenHash'] ?? null;
        $storedSubject = $challenge['subject'] ?? null;
        $storedMethod = $challenge['method'] ?? null;
        $storedPath = $challenge['path'] ?? null;
        $storedActionId = $challenge['actionId'] ?? null;

        if (!is_string($tokenHash) || !is_string($storedSubject) || !is_string($storedMethod) || !is_string($storedPath) || !is_string($storedActionId)) {
            return false;
        }

        if (!hash_equals($tokenHash, hash('sha256', $plainToken))) {
            return false;
        }

        if (!hash_equals($storedSubject, $this->resolveSubject($request))) {
            return false;
        }

        if (!hash_equals($storedMethod, $this->normalizeMethod($request->getMethod()))) {
            return false;
        }

        if (!hash_equals($storedPath, $this->normalizePath($request->getPathInfo()))) {
            return false;
        }

        return hash_equals($storedActionId, $this->normalizeActionId($actionId));
    }

    private function resolveSubject(Request $request): string
    {
        $userIdentifier = $this->extractBearerUserIdentifier($request);
        if ($userIdentifier !== null) {
            return 'user:' . $userIdentifier;
        }

        $refreshToken = trim((string) $request->cookies->get($this->refreshCookieName, ''));
        if ($refreshToken !== '') {
            return 'refresh:' . hash('sha256', $refreshToken);
        }

        $session = $this->getSession($request);
        $visitorId = trim((string) $session->get(self::SESSION_VISITOR_KEY, ''));

        if ($visitorId === '') {
            $visitorId = bin2hex(random_bytes(16));
            $session->set(self::SESSION_VISITOR_KEY, $visitorId);
        }

        return 'anon:' . $visitorId;
    }

    private function extractBearerUserIdentifier(Request $request): ?string
    {
        $authorizationHeader = trim((string) $request->headers->get('Authorization', ''));
        if ($authorizationHeader === '' || preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', trim($matches[1]));
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->decodeBase64Url($parts[1]);
        if ($payload === null) {
            return null;
        }

        $decodedPayload = json_decode($payload, true);
        if (!is_array($decodedPayload)) {
            return null;
        }

        $candidate = $decodedPayload['username'] ?? $decodedPayload['sub'] ?? null;
        if (!is_string($candidate)) {
            return null;
        }

        $normalizedCandidate = mb_strtolower(trim($candidate));

        return $normalizedCandidate === '' ? null : $normalizedCandidate;
    }

    private function decodeBase64Url(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function cleanupExpiredChallenges(SessionInterface $session): void
    {
        $challenges = $session->get(self::SESSION_CHALLENGES_KEY, []);
        if (!is_array($challenges) || $challenges === []) {
            $session->set(self::SESSION_CHALLENGES_KEY, []);

            return;
        }

        $now = time();
        $filteredChallenges = [];

        foreach ($challenges as $challengeId => $challenge) {
            if (!is_string($challengeId) || !is_array($challenge)) {
                continue;
            }

            $expiresAt = (int) ($challenge['expiresAt'] ?? 0);
            if ($expiresAt > $now) {
                $filteredChallenges[$challengeId] = $challenge;
            }
        }

        $session->set(self::SESSION_CHALLENGES_KEY, $filteredChallenges);
    }

    private function getSession(Request $request): SessionInterface
    {
        return $request->getSession();
    }

    private function normalizeMethod(string $method): string
    {
        return strtoupper(trim($method));
    }

    private function normalizePath(string $path): string
    {
        $normalizedPath = (string) parse_url(trim($path), PHP_URL_PATH);

        if ($normalizedPath === '') {
            return '/';
        }

        return '/' . ltrim($normalizedPath, '/');
    }

    private function normalizeActionId(string $actionId): string
    {
        $normalizedActionId = trim($actionId);
        if ($normalizedActionId === '' || preg_match('/^[A-Za-z0-9._:-]{3,120}$/', $normalizedActionId) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF action identifier.');
        }

        return $normalizedActionId;
    }

    private function isValidCompositeTokenFormat(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * @param array<string, mixed> $challenges
     *
     * @return array<string, mixed>
     */
    private function invalidatePreviousChallengesForAction(array $challenges, string $subject, string $actionId): array
    {
        foreach ($challenges as $challengeId => $challenge) {
            if (!is_string($challengeId) || !is_array($challenge)) {
                unset($challenges[$challengeId]);

                continue;
            }

            $storedSubject = $challenge['subject'] ?? null;
            $storedActionId = $challenge['actionId'] ?? null;

            if (!is_string($storedSubject) || !is_string($storedActionId)) {
                unset($challenges[$challengeId]);

                continue;
            }

            if (hash_equals($storedSubject, $subject) && hash_equals($storedActionId, $actionId)) {
                unset($challenges[$challengeId]);
            }
        }

        return $challenges;
    }

    private function normalizeTokenTtl(): int
    {
        return max(1, min(self::MAX_TOKEN_TTL_SECONDS, $this->tokenTtl));
    }
}
