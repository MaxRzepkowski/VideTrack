<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ReadsCredentialFields;
use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\Payload;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * An import request for any registered source. What the request must carry
 * follows from the source: a file, or the fields of its credentials form.
 */
class ImportRequest extends FormRequest
{
    use ReadsCredentialFields;

    /** The largest export accepted, in kilobytes (the page says 50 MB). */
    public const MAX_FILE_KB = 51200;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registry = app(ImportSourceRegistry::class);

        $rules = [
            'source' => ['required', 'string', Rule::in($registry->codes())],
            // Which categories to bring over and, for custom lists, which
            // lists. Sources without a selection ignore both.
            'categories' => ['array'],
            'categories.*' => ['string', 'max:'.ImportSelection::KEY_LENGTH],
            'list_ids' => ['array'],
            'list_ids.*' => ['string', 'max:'.ImportSelection::KEY_LENGTH],
        ];

        $source = $this->importSource();

        // An unknown source fails on its own rule; a file is still asked for,
        // so the form shows both errors at once.
        if ($source === null || $source instanceof ReadsFile) {
            // Browsers report the mime types of export files inconsistently,
            // so cap the size and let the source validate the contents.
            $rules['file'] = ['required', 'file', 'max:'.self::MAX_FILE_KB];
        }

        return $source instanceof ReadsCredentials ? [...$rules, ...$this->credentialRules($source)] : $rules;
    }

    /**
     * A connection source runs only on a connection that still reads; one
     * the service let go of has to be connected again first.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(#[CurrentUser] User $viewer): array
    {
        return [
            function (Validator $validator) use ($viewer): void {
                $source = $this->importSource();

                if ($source instanceof ReadsFromConnection && ! $this->connection($viewer, $source)?->isActive()) {
                    $validator->errors()->add('source', __('import.connect_your_account_first'));
                }
            },
        ];
    }

    public function connection(User $viewer, ReadsFromConnection $source): ?ServiceConnection
    {
        return $viewer->serviceConnections()
            ->where('service', $source->connectionService())
            ->first();
    }

    public function importSource(): ?ImportSource
    {
        return app(ImportSourceRegistry::class)->find($this->string('source')->value());
    }

    /** The source, once the request is validated. */
    public function source(): ImportSource
    {
        return $this->importSource() ?? throw new LogicException('An import request validated without a known source.');
    }

    /**
     * What the run reads from, as validated. A file is stashed on the local
     * disk, since the upload PHP holds disappears with this request; the run
     * deletes it when done.
     */
    public function stashInput(User $viewer): ImportInput
    {
        $source = $this->source();

        if ($source instanceof ReadsCredentials) {
            return $this->credentialInput($source);
        }

        if ($source instanceof ReadsFromConnection) {
            $connection = $this->connection($viewer, $source) ?? throw new LogicException('An import request validated without its connection.');

            return ImportInput::connection($connection->id);
        }

        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('A file import validated without its file.');
        }

        return ImportInput::file(Storage::disk('local')->path((string) $file->store('imports', 'local')), $file->getClientOriginalName());
    }

    public function selection(): ImportSelection
    {
        return new ImportSelection(
            Payload::strings($this->input('categories')),
            Payload::strings($this->input('list_ids')),
        );
    }
}
