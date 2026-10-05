<?php

namespace App\Services\Document;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVerificationStatus;
use App\Exceptions\FinancialException;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public const DISK = 'private';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Stores a file privately under a random name. Uploading the same category + title again
     * creates a new version and keeps the old one for the audit trail.
     */
    public function upload(Model $owner, UploadedFile $file, DocumentCategory $category, string $title, User $by): Document
    {
        $this->assertSafe($file);

        return DB::transaction(function () use ($owner, $file, $category, $title, $by) {
            $previous = Document::where('documentable_type', $owner->getMorphClass())->where('documentable_id', $owner->getKey())
                ->where('category', $category)->where('title', $title)->orderByDesc('version')->lockForUpdate()->first();

            // Random server-side name and extension: the client's name and extension are never trusted.
            $ext = $this->extensionFor($file);
            $path = $category->value.'/'.Str::uuid().'.'.$ext;
            Storage::disk(self::DISK)->put($path, $file->get());

            $doc = new Document([
                'category' => $category, 'title' => $title, 'uploaded_by' => $by->id,
                'original_name' => Str::limit(preg_replace('/[^\w.\- ]/', '_', $file->getClientOriginalName()), 150, ''),
                'mime_type' => $this->mimeFor($file), 'size' => $file->getSize(), 'version' => ($previous?->version ?? 0) + 1,
                'previous_version_id' => $previous?->id,
            ]);
            $doc->forceFill(['disk' => self::DISK, 'path' => $path, 'verification_status' => DocumentVerificationStatus::Pending]);
            $doc->documentable()->associate($owner);
            $doc->save();
            $this->audit->record('document.uploaded', $doc, null, ['category' => $category->value, 'version' => $doc->version]);

            return $doc;
        });
    }

    public function verify(Document $doc, User $by, bool $approved, ?string $reason = null): Document
    {
        $doc->forceFill([
            'verification_status' => $approved ? DocumentVerificationStatus::Verified : DocumentVerificationStatus::Rejected,
            'verified_by' => $by->id, 'verified_at' => now(),
        ])->save();
        $this->audit->record($approved ? 'document.verified' : 'document.rejected', $doc, null, null, $reason);

        return $doc;
    }

    /** @return \Symfony\Component\HttpFoundation\StreamedResponse */
    public function respond(Document $doc, bool $inline = false)
    {
        $disk = Storage::disk($doc->disk);
        abort_unless($disk->exists($doc->path), 404);

        $canInline = $inline && in_array($doc->mime_type, config('finance.documents.inline_mimes'), true);
        $safeName = Str::ascii($doc->original_name) ?: 'document';

        return $disk->response($doc->path, $safeName, [
            'Content-Type' => $doc->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $canInline ? 'inline' : 'attachment');
    }

    private function assertSafe(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new FinancialException('The document could not be uploaded.');
        }
        if ($file->getSize() > config('finance.documents.max_kb') * 1024) {
            throw new FinancialException('The document is too large. The limit is '.(config('finance.documents.max_kb') / 1024).' MB.');
        }
        if (! in_array($this->extensionFor($file), config('finance.documents.mimes'), true)) {
            throw new FinancialException('Only PDF, JPG and PNG files are accepted.');
        }
    }

    /** Detect the real type from content, not from the client-supplied name. */
    private function mimeFor(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        return ($path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : null) ?: 'application/octet-stream';
    }

    private function extensionFor(UploadedFile $file): string
    {
        return match ($this->mimeFor($file)) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }
}
