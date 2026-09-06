<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * Azure with a token on it.
 *
 * Thin on purpose. The collectors — {@see Graph}, {@see Cost},
 * {@see Subscriptions} — take a plain callable rather than one of these, so the
 * logic worth testing (paging, resumption, column mapping) can be tested with
 * no HTTP, no Guzzle and no tenant. This class is the wiring that makes those
 * callables real.
 */
final class Client
{
    /**
     * @param array<string,string> $credentials
     */
    public function __construct(
        private readonly array $credentials,
        private readonly Http $http,
    ) {
    }

    /** @return array<mixed> */
    public function subscriptions(): array
    {
        return $this->http->getJson(Endpoints::subscriptions(), $this->authorisation());
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>
     */
    public function graph(array $body): array
    {
        return $this->http->postJson(Endpoints::resourceGraph(), $body, $this->authorisation());
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>
     */
    public function costQuery(string $subscription_id, array $body): array
    {
        return $this->http->postJson(Endpoints::costQuery($subscription_id), $body, $this->authorisation());
    }

    /** @return array<string,string> */
    private function authorisation(): array
    {
        return ['Authorization' => 'Bearer ' . Auth::token($this->credentials, $this->http)];
    }
}
