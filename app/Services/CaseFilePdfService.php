<?php

namespace App\Services;

use App\Models\DisputeCase;
use App\Models\DisputeMessage;
use Illuminate\Support\Facades\Storage;

/**
 * Dispute case file (Module 7).
 *
 * Produces a self-contained, dependency-free PDF 1.4 document containing the
 * case header, frozen amounts, decision, appeal state, timeline/chat and the
 * evidence hash, plus a canonical SHA-256 digest of the whole dossier so an
 * external reviewer can prove the file was not altered after generation.
 */
class CaseFilePdfService
{
    public const DISK = 'local';

    /**
     * Build the dossier payload and store the PDF. Returns storage metadata.
     *
     * @return array{path: string, file_hash: string, size_bytes: int, generated_at: string, dossier: array<string, mixed>}
     */
    public function generate(DisputeCase $dispute): array
    {
        $dispute->loadMissing(['protectedPayment.buyer', 'protectedPayment.merchant', 'openedBy', 'decidedBy', 'appealedBy']);

        $timeline = DisputeMessage::where('dispute_case_id', $dispute->id)
            ->where('is_internal', false)
            ->with('author:id,first_name,last_name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $dossier = $this->dossier($dispute, $timeline);
        $dossierHash = hash('sha256', json_encode($dossier, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $pdf = $this->renderPdf($dossier, $dossierHash);
        $path = 'case-files/' . $dispute->case_reference . '.pdf';

        Storage::disk(self::DISK)->put($path, $pdf);

        $dispute->update([
            'case_file_path' => $path,
            'case_file_generated_at' => now(),
        ]);

        AuditLogService::log('dispute.case_file_generated', null, [
            'case_id' => $dispute->case_id,
            'case_reference' => $dispute->case_reference,
            'path' => $path,
            'dossier_hash' => $dossierHash,
        ]);

        return [
            'path' => $path,
            'file_hash' => $dossierHash,
            'size_bytes' => strlen($pdf),
            'generated_at' => now()->toIso8601String(),
            'dossier' => $dossier,
        ];
    }

    public function contents(DisputeCase $dispute): string
    {
        $path = $dispute->case_file_path ?: $this->generate($dispute)['path'];

        return (string) Storage::disk(self::DISK)->get($path);
    }

    /**
     * Serializable dossier (also useful for appeals and regulator requests).
     *
     * @param \Illuminate\Support\Collection<int, DisputeMessage> $timeline
     * @return array<string, mixed>
     */
    public function dossier(DisputeCase $dispute, $timeline): array
    {
        $payment = $dispute->protectedPayment;

        return [
            'case_reference' => $dispute->case_reference,
            'case_id' => $dispute->case_id,
            'case_type' => $dispute->case_type?->value,
            'status' => $dispute->status,
            'currency' => $dispute->currency,
            'disputed_amount_minor' => (int) $dispute->disputed_amount_minor,
            'reason' => $dispute->reason,
            'opened_by' => $this->personName($dispute->openedBy),
            'opened_at' => $dispute->created_at?->toIso8601String(),
            'sla_deadline_at' => $dispute->sla_deadline_at?->toIso8601String(),
            'decision' => [
                'outcome' => $dispute->decision_outcome?->value,
                'release_to_merchant_minor' => (int) $dispute->release_to_merchant_minor,
                'refund_to_buyer_minor' => (int) $dispute->refund_to_buyer_minor,
                'reason_code' => $dispute->decision_reason_code,
                'notes' => $dispute->decision_notes,
                'decided_by' => $this->personName($dispute->decidedBy),
                'decided_at' => $dispute->decided_at?->toIso8601String(),
                'appeal_deadline_at' => $dispute->appeal_deadline_at?->toIso8601String(),
            ],
            'appeal' => [
                'count' => (int) $dispute->appeal_count,
                'reason' => $dispute->appeal_reason,
                'filed_by' => $this->personName($dispute->appealedBy),
                'filed_at' => $dispute->appealed_at?->toIso8601String(),
                'original_outcome' => $dispute->original_decision_outcome,
            ],
            'agreement' => [
                'protected_payment_id' => $payment?->protected_payment_id,
                'reference' => $payment?->reference,
                'principal_amount_minor' => (int) ($payment?->principal_amount_minor ?? 0),
                'released_amount_minor' => (int) ($payment?->released_amount_minor ?? 0),
                'refunded_amount_minor' => (int) ($payment?->refunded_amount_minor ?? 0),
                'buyer' => $this->personName($payment?->buyer),
                'seller' => $this->personName($payment?->merchant),
            ],
            'timeline' => $timeline->map(fn (DisputeMessage $message): array => [
                'at' => $message->created_at?->toIso8601String(),
                'type' => $message->message_type?->value,
                'role' => $message->author_role,
                'author' => $this->personName($message->author),
                'body' => $message->body,
                'attachments' => $message->attachments ?? [],
            ])->all(),
        ];
    }

    protected function personName($user): ?string
    {
        if (!$user) {
            return null;
        }

        return trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))) ?: ('User #' . $user->id);
    }

    /**
     * Render a minimal, valid PDF 1.4 document (no external dependency).
     *
     * @param array<string, mixed> $dossier
     */
    public function renderPdf(array $dossier, string $dossierHash): string
    {
        $lines = $this->layoutLines($dossier, $dossierHash);
        $pages = array_chunk($lines, 46);

        $objects = [];
        $pageObjectNumbers = [];
        $contentObjectNumbers = [];

        // 1 = catalog, 2 = pages, 3 = font, then page/content pairs.
        $next = 4;

        foreach ($pages as $index => $pageLines) {
            $contentObjectNumbers[$index] = $next++;
            $pageObjectNumbers[$index] = $next++;
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

        $kids = implode(' ', array_map(fn (int $num): string => "{$num} 0 R", $pageObjectNumbers));
        $objects[2] = "<< /Type /Pages /Count " . count($pages) . " /Kids [{$kids}] >>";

        foreach ($pages as $index => $pageLines) {
            $content = $this->contentStream($pageLines);
            $objects[$contentObjectNumbers[$index]] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

            $objects[$pageObjectNumbers[$index]] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                . "/Resources << /Font << /F1 3 0 R >> >> "
                . "/Contents {$contentObjectNumbers[$index]} 0 R >>";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }

        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param array<string, mixed> $dossier
     * @return array<int, string>
     */
    protected function layoutLines(array $dossier, string $dossierHash): array
    {
        $money = fn (int $minor): string => 'TZS ' . number_format($minor / 100, 2);

        $lines = [
            'AIRLIPA ESCROW - DISPUTE CASE FILE',
            'Case: ' . $dossier['case_reference'] . '   Type: ' . ($dossier['case_type'] ?? '-') . '   Status: ' . $dossier['status'],
            'Agreement: ' . ($dossier['agreement']['reference'] ?? '-') . '   (' . ($dossier['agreement']['protected_payment_id'] ?? '-') . ')',
            'Buyer: ' . ($dossier['agreement']['buyer'] ?? '-') . '   Seller: ' . ($dossier['agreement']['seller'] ?? '-'),
            'Principal: ' . $money((int) $dossier['agreement']['principal_amount_minor'])
                . '   Released: ' . $money((int) $dossier['agreement']['released_amount_minor'])
                . '   Refunded: ' . $money((int) $dossier['agreement']['refunded_amount_minor']),
            'Disputed: ' . $money((int) $dossier['disputed_amount_minor']) . '   Opened: ' . ($dossier['opened_at'] ?? '-'),
            'SLA deadline: ' . ($dossier['sla_deadline_at'] ?? '-'),
            'Reason: ' . $this->wrap($dossier['reason'], 92),
            '',
            'DECISION',
            'Outcome: ' . ($dossier['decision']['outcome'] ?? 'PENDING')
                . '   Release: ' . $money((int) $dossier['decision']['release_to_merchant_minor'])
                . '   Refund: ' . $money((int) $dossier['decision']['refund_to_buyer_minor']),
            'Reason code: ' . ($dossier['decision']['reason_code'] ?? '-') . '   Decided by: ' . ($dossier['decision']['decided_by'] ?? '-')
                . '   At: ' . ($dossier['decision']['decided_at'] ?? '-'),
            'Notes: ' . $this->wrap((string) ($dossier['decision']['notes'] ?? '-'), 92),
            'Appeal deadline: ' . ($dossier['decision']['appeal_deadline_at'] ?? '-'),
            '',
            'APPEAL',
            'Appeals used: ' . $dossier['appeal']['count'] . '   Original outcome: ' . ($dossier['appeal']['original_outcome'] ?? '-'),
            'Filed by: ' . ($dossier['appeal']['filed_by'] ?? '-') . '   At: ' . ($dossier['appeal']['filed_at'] ?? '-'),
            'Reason: ' . $this->wrap((string) ($dossier['appeal']['reason'] ?? '-'), 92),
            '',
            'TIMELINE AND CORRESPONDENCE',
        ];

        foreach ($dossier['timeline'] as $entry) {
            $lines[] = sprintf(
                '[%s] %s %s(%s): %s',
                $entry['at'] ?? '-',
                $entry['type'] ?? '-',
                $entry['author'] ? $entry['author'] . ' ' : '',
                $entry['role'] ?? '-',
                $this->wrap((string) $entry['body'], 92)
            );
            $lines[] = '';
        }

        $lines[] = 'INTEGRITY';
        $lines[] = 'Dossier SHA-256: ' . $dossierHash;
        $lines[] = 'Generated: ' . now()->toIso8601String() . ' by Airlipa escrow platform';

        return $lines;
    }

    protected function wrap(string $text, int $width): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        return trim(chunk_split($text, $width, "\n"));
    }

    /**
     * @param array<int, string> $lines
     */
    protected function contentStream(array $lines): string
    {
        $stream = "BT\n/F1 9 Tf\n12 TL\n40 800 Td\n";

        foreach ($lines as $line) {
            $stream .= $this->escape($line) . " T*\n";
        }

        return $stream . 'ET';
    }

    protected function escape(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
