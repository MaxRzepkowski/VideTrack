<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ReadsCredentialFields;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * A look at a file or an account before importing it. Only a source that
 * reads a file or a form can be looked at; any other code in the address is
 * a 404. Nothing is stored: the file is read where PHP left it.
 */
class AnalyzeImportRequest extends FormRequest
{
    use ReadsCredentialFields;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $source = $this->source();

        return $source instanceof ReadsFile
            ? ['file' => ['required', 'file', 'max:'.ImportRequest::MAX_FILE_KB]]
            : $this->credentialRules($source);
    }

    public function source(): ReadsFile|ReadsCredentials
    {
        $source = app(ImportSourceRegistry::class)->find((string) $this->route('source'));

        return $source instanceof ReadsFile || $source instanceof ReadsCredentials ? $source : abort(404);
    }

    /** What the analysis reads, as validated. */
    public function analysisInput(): ImportInput
    {
        $source = $this->source();

        if ($source instanceof ReadsCredentials) {
            return $this->credentialInput($source);
        }

        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('A file analysis validated without its file.');
        }

        return ImportInput::file((string) $file->getRealPath(), $file->getClientOriginalName());
    }
}
