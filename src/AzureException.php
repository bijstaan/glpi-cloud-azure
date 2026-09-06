<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

use RuntimeException;

/**
 * What went wrong, in the four categories a sweep has to tell apart.
 *
 * They are four because the right response differs: a credential problem needs
 * an administrator, a throttle needs patience, a transport failure needs
 * retrying later, and an unusable body needs somebody to look at this plugin.
 * Collapsing them into one message sends people to check their firewall for an
 * afternoon because Azure asked us to slow down.
 */
final class AzureException extends RuntimeException
{
    /** Entra refused the credentials, or the app has no access. */
    public const AUTH = 'auth';

    /** Asked to slow down. `retryAfter` says for how long, when Azure said. */
    public const THROTTLED = 'throttled';

    /** Could not reach it, or it did not answer in time. */
    public const TRANSPORT = 'transport';

    /** Answered with something we cannot use. */
    public const RESPONSE = 'response';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $status = 0,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->kind === self::THROTTLED || $this->kind === self::TRANSPORT;
    }
}
