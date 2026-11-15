<?php

namespace Tests\Fakes;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\DevicePoll;
use App\Models\ServiceConnection;
use App\Support\ServiceUnavailable;
use Closure;

/**
 * A device-flow service whose answers the test scripts: each poll takes
 * the next entry of $polls. It counts polls, so a test can prove the
 * controller kept the service's pace instead of passing every poll on.
 */
class FakeDeviceService implements AuthorizesByDevice, RevokesAccess
{
    public const CODE = 'fake-device';

    /**
     * A closure runs while the service "answers" and gives the answer.
     *
     * @var list<DevicePoll|ConnectionGrant|ConnectionRejected|ServiceUnavailable|Closure(): DevicePoll>
     */
    public static array $polls = [];

    public static string $deviceCode = 'secret-device-code';

    public static ?ServiceUnavailable $beginFails = null;

    public static int $pollCount = 0;

    /** @var list<string> device codes seen, in order */
    public static array $seenDeviceCodes = [];

    /** @var list<int> ids of the connections revoked */
    public static array $revoked = [];

    public static bool $revokeFails = false;

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Fake Device';
    }

    public function beginDeviceAuthorization(): DeviceAuthorization
    {
        if (self::$beginFails !== null) {
            throw self::$beginFails;
        }

        return new DeviceAuthorization(
            deviceCode: self::$deviceCode,
            userCode: 'ABCD-EFGH',
            verificationUri: 'https://example.test/pin',
            verificationUriComplete: 'https://example.test/pin?user_code=ABCD-EFGH',
            expiresIn: 900,
            interval: 5,
        );
    }

    public function pollDeviceAuthorization(string $deviceCode): DevicePoll|ConnectionGrant
    {
        self::$pollCount++;
        self::$seenDeviceCodes[] = $deviceCode;

        $next = array_shift(self::$polls) ?? DevicePoll::Pending;

        if ($next instanceof Closure) {
            $next = $next();
        }

        if ($next instanceof ConnectionRejected || $next instanceof ServiceUnavailable) {
            throw $next;
        }

        return $next;
    }

    public function revoke(ServiceConnection $connection): void
    {
        if (self::$revokeFails) {
            throw new ServiceUnavailable('Service down.');
        }

        self::$revoked[] = $connection->id;
    }
}
