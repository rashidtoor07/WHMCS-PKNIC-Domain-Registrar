<?php

declare(strict_types=1);

namespace PknicWeb;

/** Entry points shared by the cron hook, the CLI script, the module and the admin addon. */
final class Runner
{
    public static function config(): Config
    {
        if (!function_exists('getRegistrarConfigOptions') && defined('ROOTDIR')) {
            require_once ROOTDIR . '/includes/registrarfunctions.php';
        }
        $params = function_exists('getRegistrarConfigOptions') ? (array) getRegistrarConfigOptions('pknicweb') : [];
        if (empty($params['Username'])) {
            throw new PknicException('The pknicweb registrar module is not activated/configured');
        }
        return Config::fromParams($params);
    }

    public static function actions(Config $config): PknicActions
    {
        return new PknicActions(new PknicClient($config, MapLoader::load()));
    }

    public static function run(string $source, int $maxJobs, int $maxSeconds): array
    {
        try {
            $config = self::config();
        } catch (\Throwable $e) {
            return ['processed' => 0, 'status' => 'skipped: ' . $e->getMessage()];
        }
        $processor = new JobProcessor(
            $config,
            new JobQueue(),
            new Settings(),
            new Notifier($config),
            fn () => self::actions($config)
        );
        $result = $processor->run($maxJobs, $maxSeconds);
        $result['source'] = $source;
        return $result;
    }

    /** Fresh login from an empty cookie jar. @return array{ok: bool, message: string} */
    public static function testLogin(): array
    {
        try {
            $client = self::actions(self::config())->client();
            $client->clearSession();
            $client->ensureLogin();
            (new Settings())->recordLoginSuccess();
            return ['ok' => true, 'message' => 'Logged in to PKNIC successfully (logged-in marker found).'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
