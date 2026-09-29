<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial;

/**
 * The outcome of GET /credencial/credencial — design Interfaces section:
 * `{state: active|blocked|no_photo|not_a_player, photo_request, credential}`.
 *
 * A single value object, built only through its four named constructors, so
 * CredencialController never has to hand-assemble this shape (and never
 * risks a state/credential combination the design does not allow — e.g. a
 * `blocked` response that still carries a credential).
 */
final class CredencialState {

    private function __construct(
        private readonly string $state,
        private readonly ?array $photoRequest,
        private readonly ?array $credential
    ) {
    }

    /**
     * @param array<string, mixed> $credential
     * @param array{id:int, status:string, created_at:string}|null $photoRequest
     */
    public static function active( array $credential, ?array $photoRequest = null ): self {
        return new self( 'active', $photoRequest, $credential );
    }

    public static function blocked(): self {
        return new self( 'blocked', null, null );
    }

    public static function noPhoto(): self {
        return new self( 'no_photo', null, null );
    }

    public static function notAPlayer(): self {
        return new self( 'not_a_player', null, null );
    }

    /** @return array{state:string, photo_request:?array, credential:?array} */
    public function toArray(): array {
        return [
            'state'         => $this->state,
            'photo_request' => $this->photoRequest,
            'credential'    => $this->credential,
        ];
    }
}
