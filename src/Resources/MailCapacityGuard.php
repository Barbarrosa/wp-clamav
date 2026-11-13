<?php
declare(strict_types=1);
namespace WPClamAV\Resources;

/** Checks the complete MIME message before consuming a recipient token. */
final class MailCapacityGuard
{
    public function __construct(private array $budgets, private int $phpHeadroomBytes)
    {
        if ($phpHeadroomBytes < 0) { throw new \InvalidArgumentException('Negative PHP headroom.'); }
    }

    private function number(string $key): ?float
    {
        $value = $this->budgets[$key] ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid mail allocation: ' . $key);
        }
        return (float) $value;
    }

    private static function sum(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if ($value < 0 || $value > PHP_INT_MAX - $sum) { throw new \InvalidArgumentException('Mail size cannot be represented.'); }
            $sum += $value;
        }
        return $sum;
    }

    public static function encodedAttachmentBytes(int $bytes): int
    {
        if ($bytes < 0 || $bytes > intdiv(PHP_INT_MAX - 8, 4)) { throw new \InvalidArgumentException('Attachment size cannot be represented.'); }
        $encoded = 4 * intdiv($bytes + 2, 3);
        return self::sum($encoded, 2 * intdiv($encoded + 75, 76));
    }

    /**
     * A conservative bound for UTF-8 subject/body transfer encoding and folding.
     * Overhead must cover the sender/recipient headers, MIME framing, filenames
     * and any installation-specific mail additions. It is charged exactly once.
     * @param list<int> $attachmentSizes Only originals actually attached.
     */
    public static function estimatedMessageBytes(string $subject, string $body, array $attachmentSizes, int $overhead): int
    {
        $text = self::sum(strlen($subject), strlen($body));
        if ($text > intdiv(PHP_INT_MAX, 4)) { throw new \InvalidArgumentException('Mail text cannot be represented.'); }
        $total = self::sum($text * 4, $overhead);
        foreach ($attachmentSizes as $size) {
            if (!is_int($size)) { throw new \InvalidArgumentException('Attachment lengths must be integers.'); }
            $total = self::sum($total, self::encodedAttachmentBytes($size));
        }
        return $total;
    }

    /** $messageBytes already includes encoding, framing and headers. */
    public function message(int $attachedOriginalBytes, int $messageBytes): array
    {
        self::sum($attachedOriginalBytes, $messageBytes);
        $message = $this->number('mail_message_bytes');
        $assembly = $this->number('mail_php_bytes');
        $overhead = $this->number('mail_message_overhead_bytes');
        $multiplier = $this->number('mail_memory_multiplier');
        $reasons = [];
        foreach (['mail_message_bytes' => $message, 'mail_php_bytes' => $assembly,
            'mail_message_overhead_bytes' => $overhead, 'mail_memory_multiplier' => $multiplier] as $key => $value) {
            if ($value === null) { $reasons[] = $key . '_unknown'; }
        }
        if ($multiplier !== null && $multiplier < 1) { $reasons[] = 'mail_memory_multiplier_below_one'; }
        if ($message !== null && $messageBytes > $message) { $reasons[] = 'complete_message_exceeds_allocation'; }
        if ($overhead !== null && $messageBytes < $overhead) { $reasons[] = 'complete_message_missing_framing_allowance'; }
        $raw = $this->number('mail_attachment_bytes');
        if ($raw !== null && $attachedOriginalBytes > $raw) { $reasons[] = 'original_attachment_exceeds_allocation'; }
        $required = $multiplier === null ? null : ceil(($attachedOriginalBytes + $messageBytes) * $multiplier);
        if ($required !== null && (!is_finite($required) || $required > PHP_INT_MAX)) { $reasons[] = 'mail_memory_requirement_overflows'; }
        if ($required !== null && ($assembly !== null && $required > $assembly || $required > $this->phpHeadroomBytes)) {
            $reasons[] = 'insufficient_php_assembly_headroom';
        }
        return ['allowed' => $reasons === [], 'reason_codes' => array_values(array_unique($reasons)),
            'message_bytes' => $messageBytes, 'attached_original_bytes' => $attachedOriginalBytes,
            'required_php_bytes' => $required !== null && is_finite($required) && $required <= PHP_INT_MAX ? (int) $required : null,
            'available_php_bytes' => $assembly === null ? null : (int) floor(min($assembly, $this->phpHeadroomBytes))];
    }

    /** An early attachment gate; a complete-message check must still follow. */
    public function attachment(int $originalBytes, int $encodedBytes): array
    {
        if ($encodedBytes < self::encodedAttachmentBytes($originalBytes)) { throw new \InvalidArgumentException('Attachment estimate omits MIME encoding.'); }
        $overhead = $this->number('mail_message_overhead_bytes');
        return $this->message($originalBytes, self::sum($encodedBytes, $overhead === null ? 0 : (int) ceil($overhead)));
    }

    public function messageAllowed(int $attachedOriginalBytes, int $messageBytes): bool
    {
        return $this->message($attachedOriginalBytes, $messageBytes)['allowed'];
    }

    public function attachmentAllowed(int $originalBytes, int $encodedBytes): bool
    {
        return $this->attachment($originalBytes, $encodedBytes)['allowed'];
    }
}
