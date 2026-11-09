<?php

namespace App\View\Components\Ui;

use App\View\InlineErrors;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\View;

/**
 * A label, its control and the control's error. The control id defaults to the field name as a slug, dots and
 * brackets as hyphens (`parcel.weight_kg` → `f-parcel-weight-kg`), so `for` matches the id the form gives it.
 *
 * Its public properties are what the x-ui control components inside it read with @aware: when the field's error key
 * has a message they mark themselves invalid (`aria-invalid`, `aria-describedby` → the error, `is-invalid`). The
 * shown error is recorded so the layout's flash does not list it again; `keepFlash` leaves it in the flash too, for
 * a field the page renders hidden.
 */
class Field extends Component
{
    public ?string $fieldControlId;

    public ?string $fieldKey;

    public ?string $fieldMessage;

    public function __construct(
        public ?string $label = null,
        public ?string $for = null,
        public ?string $name = null,
        public bool $required = false,
        public bool $wide = false,
        public ?string $errorKey = null,
        public bool $keepFlash = false,
    ) {
        $this->fieldKey = $errorKey ?? $name;
        $this->fieldControlId = $for ?? ($name !== null && $name !== '' ? self::controlId($name) : null);
        $message = $this->fieldKey !== null && $this->fieldKey !== '' ? self::errors()?->first($this->fieldKey) : null;
        $this->fieldMessage = is_string($message) && $message !== '' ? $message : null;

        if ($this->fieldMessage !== null && ! $keepFlash) {
            InlineErrors::mark($this->fieldKey);
        }
    }

    /** `recipient.first_name` / `recipient[first_name]` → `f-recipient-first-name`. */
    public static function controlId(string $name): string
    {
        return 'f-'.Str::slug(str_replace(['.', '[', ']'], ['-', '-', ''], $name), '-');
    }

    /**
     * A control's attributes inside a field: its base class, plus `is-invalid`, `aria-invalid` and the error's id in
     * `aria-describedby` (after any it already has) when the field shows an error.
     */
    public static function control(ComponentAttributeBag $attributes, ?string $controlId, ?string $message, string $class): ComponentAttributeBag
    {
        $bad = $message !== null && $message !== '';
        $describedBy = trim(implode(' ', array_filter([
            trim((string) $attributes->get('aria-describedby')),
            $bad && $controlId !== null ? $controlId.'-error' : '',
        ])));

        $merged = $attributes->except(['aria-describedby'])->merge(['class' => $class.($bad ? ' is-invalid' : '')])->getAttributes();
        // A control that states its own aria-invalid keeps it.
        $merged += ['aria-invalid' => $bad ? 'true' : null];
        $merged['aria-describedby'] = $describedBy === '' ? null : $describedBy;

        return new ComponentAttributeBag($merged);
    }

    private static function errors(): ?ViewErrorBag
    {
        $errors = view()->shared('errors') ?? session('errors');

        return $errors instanceof ViewErrorBag ? $errors : null;
    }

    public function render(): View
    {
        return view('components.ui.field');
    }
}
