<?php
declare(strict_types=1);

class FreshRSS_ActionController {
	public function firstAction(): void {}
}

final class InteractionAnalyticsDAO {
	/** @var list<array{feed_ids:list<int>,all:bool}> */
	public array $deletions = [];

	/** @param list<int> $feedIds */
	public function delete(array $feedIds, bool $all): bool {
		$this->deletions[] = ['feed_ids' => $feedIds, 'all' => $all];
		return true;
	}
}

final class InteractionAnalyticsExtension {
	public InteractionAnalyticsDAO $dao;

	public function __construct() {
		$this->dao = new InteractionAnalyticsDAO();
	}

	public function dao(): InteractionAnalyticsDAO {
		return $this->dao;
	}
}

final class Minz_ExtensionManager {
	public static InteractionAnalyticsExtension $extension;

	public static function findExtension(string $name): InteractionAnalyticsExtension {
		return self::$extension;
	}
}

final class Minz_Error {
	public static function error(int $status): void {}
}

final class Minz_Request {
	public static bool $all = false;
	/** @var list<array{message:string,url:array<string,mixed>}> */
	public static array $goodCalls = [];

	public static function isPost(): bool {
		return true;
	}

	public static function paramBoolean(string $name): bool {
		return $name === 'all' && self::$all;
	}

	/** @return list<int> */
	public static function paramArrayInt(string $name): array {
		return self::$all ? [] : [303];
	}

	/** @param array<string,mixed> $url */
	public static function good(string $message, array $url = []): void {
		self::$goodCalls[] = ['message' => $message, 'url' => $url];
	}

	/** @param array<string,mixed> $url */
	public static function bad(string $message, array $url = []): void {
		throw new RuntimeException('Unexpected bad redirect: ' . $message);
	}
}

function _t(string $key): string {
	return $key;
}

Minz_ExtensionManager::$extension = new InteractionAnalyticsExtension();
ob_start();
register_shutdown_function(static function (): void {
	$body = (string)ob_get_clean();
	$deletions = Minz_ExtensionManager::$extension->dao->deletions;
	if (count(Minz_Request::$goodCalls) !== 2 || count($deletions) !== 2 || str_contains($body, '{"ok"')) {
		fwrite(STDERR, "Delete form returned JSON instead of redirecting to its configuration page.\n");
		exit(1);
	}
	if ($deletions[0] !== ['feed_ids' => [303], 'all' => false] || $deletions[1] !== ['feed_ids' => [], 'all' => true]) {
		fwrite(STDERR, "Delete form did not preserve selected/all semantics.\n");
		exit(1);
	}
	echo "Delete form fallback redirects to the configuration page.\n";
});

require __DIR__ . '/../Controllers/interactionAnalyticsController.php';
$controller = new FreshExtension_interactionAnalytics_Controller();
$controller->deleteAction();
Minz_Request::$all = true;
$controller->deleteAction();
