<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Identity;

/**
 * The exact thing a signature authorizes.
 *
 * A confirmation flag says yes without knowing to what: `--yes` on `plugins.remove` consents to
 * removal in the abstract, so the same consent covers removing any plugin, on any host, at any
 * later moment. This carries the target with it, which is what turns consent into authorization —
 * a signature over `MailPlugin` cannot be presented to remove `BillingPlugin`, because the bytes
 * that were signed name one and not the other.
 *
 * Every field is load-bearing:
 *
 * - `operation` and `arguments` — what is being authorized, in full.
 * - `host` — where. An authorization produced against staging is not an authorization in
 *   production, and without this the two are the same string.
 * - `issuedAt` — when, so a signature captured today cannot be presented next month.
 * - `nonce` — which one, so it cannot be presented twice inside the freshness window either.
 */
final readonly class OperationAuthorization
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $operation,
        public array $arguments,
        public string $host,
        public string $issuedAt,
        public string $nonce,
    ) {
    }

    /**
     * The bytes that get signed and verified — identical on both sides or nothing works.
     *
     * Sorted keys and no whitespace, because JSON has many spellings of the same object and a
     * signature is over bytes, not over meaning. If the signer serialized `{"a":1,"b":2}` and the
     * verifier rebuilt `{"b":2,"a":1}`, every valid authorization would be rejected — a failure
     * that looks like an attack and is a formatting difference.
     */
    public function canonical(): string
    {
        return (string) json_encode([
            'arguments' => self::sortDeep($this->arguments),
            'host' => $this->host,
            'issuedAt' => $this->issuedAt,
            'nonce' => $this->nonce,
            'operation' => $this->operation,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sorts every map in the structure, at every depth, and leaves every list alone.
     *
     * The top level being fixed by hand was not enough: the arguments are a map too, and
     * `['b'=>2,'a'=>1]` from the caller against `['a'=>1,'b'=>2]` from the signer is the same call
     * spelled two ways. Unsorted, it refuses honest authorizations with a message about a
     * mismatched call — the worst kind of false alarm, because the reader believes it.
     *
     * Lists keep their order on purpose. In a map, key order carries no meaning; in a list it is
     * the meaning, and sorting `['drop', 'create']` into `['create', 'drop']` would quietly agree
     * to a different operation.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortDeep(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        return $value;
    }

    /**
     * How long ago this was issued, in seconds, against the supplied clock.
     *
     * Negative when the stamp is in the future — which the authorizer treats as a failure rather
     * than as "very fresh", since a clock ahead of ours is either broken or being helped.
     */
    public function ageInSeconds(int $now): int
    {
        $issued = strtotime($this->issuedAt);

        return $issued === false ? \PHP_INT_MAX : $now - $issued;
    }

    /**
     * Rebuild from the canonical bytes, or null when they are not a well-formed authorization.
     *
     * Used by the verifying side, which must parse what it was handed rather than trust a
     * structure it built itself.
     */
    public static function fromCanonical(string $json): ?self
    {
        /** @var mixed $data */
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            return null;
        }

        foreach (['operation', 'host', 'issuedAt', 'nonce'] as $key) {
            if (!\is_string($data[$key] ?? null) || $data[$key] === '') {
                return null;
            }
        }
        if (!\is_array($data['arguments'] ?? null)) {
            return null;
        }

        /** @var array{operation: string, arguments: array<string, mixed>, host: string, issuedAt: string, nonce: string} $data */
        return new self(
            operation: $data['operation'],
            arguments: $data['arguments'],
            host: $data['host'],
            issuedAt: $data['issuedAt'],
            nonce: $data['nonce'],
        );
    }
}
