<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

/**
 * Akcja na wierszu zasobu. `edit`/`delete` są wbudowane (klient zna ich semantykę);
 * `custom` uderza w dowolny endpoint modułu — Core nie zna jego semantyki.
 */
final class Action
{
    private function __construct(
        private readonly string $type,
        private readonly string $key,
        private string $label = '',
        private ?string $icon = null,
        private ?string $endpoint = null,
        private ?string $permission = null,
        private bool $danger = false,
        private ?string $confirm = null,
    ) {}

    public static function edit(): self
    {
        return new self('edit', 'edit', 'Edytuj', 'pencil');
    }

    public static function delete(): self
    {
        return (new self('delete', 'delete', 'Usuń', 'trash-2'))
            ->danger()
            ->confirm('Tej operacji nie można cofnąć.');
    }

    /** @param string $endpoint format "METHOD /v1/..." z opcjonalnym {id} */
    public static function custom(string $key, string $endpoint): self
    {
        return new self('custom', $key, endpoint: $endpoint);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function danger(bool $danger = true): self
    {
        $this->danger = $danger;

        return $this;
    }

    public function confirm(string $message): self
    {
        $this->confirm = $message;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'endpoint' => $this->endpoint,
            'permission' => $this->permission,
            'danger' => $this->danger,
            'confirm' => $this->confirm,
        ], static fn (mixed $v): bool => $v !== false && $v !== null && $v !== '');
    }
}
