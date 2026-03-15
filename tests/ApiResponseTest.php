<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/ApiResponse.php';

class ApiResponseTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private function assert(bool $condition, string $label): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = $label;
            echo "  [FAIL] {$label}\n";
        }
    }

    public function testParseJsonBody(): void {
        echo "\n--- ApiResponse::parseJsonBody ---\n";

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = ApiResponse::parseJsonBody(false);
        $this->assert($result === [], 'Empty input returns empty array when not required');
    }

    private function runInSubprocess(string $snippet): array {
        $script = '<?php require_once ' . var_export(__DIR__ . '/bootstrap.php', true) . '; require_once ' . var_export(__DIR__ . '/../includes/ApiResponse.php', true) . '; ' . $snippet;
        $tmpFile = tempnam(sys_get_temp_dir(), 'api_test_') . '.php';
        file_put_contents($tmpFile, $script);
        $output = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>/dev/null');
        unlink($tmpFile);
        return json_decode(trim($output), true) ?? [];
    }

    public function testPaginatedStructure(): void {
        echo "\n--- ApiResponse::paginated structure ---\n";

        $decoded = $this->runInSubprocess('ApiResponse::paginated(["a","b","c"], 100, 2, 10);');

        $this->assert(isset($decoded['success']) && $decoded['success'] === true, 'success = true');
        $this->assert(isset($decoded['data']) && count($decoded['data']) === 3, 'data has 3 items');
        $this->assert(isset($decoded['pagination']), 'pagination key exists');
        $this->assert($decoded['pagination']['total'] === 100, 'total = 100');
        $this->assert($decoded['pagination']['current_page'] === 2, 'current_page = 2');
        $this->assert($decoded['pagination']['total_pages'] === 10, 'total_pages = 10');
        $this->assert($decoded['pagination']['has_prev'] === true, 'has_prev = true');
        $this->assert($decoded['pagination']['has_next'] === true, 'has_next = true');
    }

    public function testErrorResponse(): void {
        echo "\n--- ApiResponse error responses ---\n";

        $decoded = $this->runInSubprocess('ApiResponse::validationError(["email"=>"required","name"=>"required"], "Bad input");');

        $this->assert(isset($decoded['success']) && $decoded['success'] === false, 'success = false');
        $this->assert($decoded['error_code'] === 'VALIDATION_ERROR', 'error_code = VALIDATION_ERROR');
        $this->assert(isset($decoded['errors']) && count($decoded['errors']) === 2, 'errors has 2 items');
    }

    public function testSuccessResponse(): void {
        echo "\n--- ApiResponse success responses ---\n";

        $decoded = $this->runInSubprocess('ApiResponse::success(["user_id"=>42,"token"=>"abc123"], "Login successful");');

        $this->assert($decoded['success'] === true, 'success = true');
        $this->assert($decoded['message'] === 'Login successful', 'message correct');
        $this->assert($decoded['user_id'] === 42, 'user_id in payload');
    }

    public function run(): void {
        echo "=== ApiResponseTest ===\n";
        $this->testParseJsonBody();
        $this->testPaginatedStructure();
        $this->testErrorResponse();
        $this->testSuccessResponse();
        $this->printSummary();
    }

    private function printSummary(): void {
        echo "\nPassed: {$this->passed} | Failed: {$this->failed}\n";
        if (!empty($this->failures)) {
            echo "Failures:\n";
            foreach ($this->failures as $f) echo "  - {$f}\n";
        }
    }

    public function getFailed(): int { return $this->failed; }
}
