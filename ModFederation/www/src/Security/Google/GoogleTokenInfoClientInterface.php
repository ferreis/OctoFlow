<?php

namespace App\Security\Google;

interface GoogleTokenInfoClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function fetchTokenInfo(string $idToken): array;
}
