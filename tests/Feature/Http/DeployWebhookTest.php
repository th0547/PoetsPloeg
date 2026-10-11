<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DeployWebhookTest extends TestCase
{
    private string $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trigger = storage_path('app/deploy.trigger');
        File::delete($this->trigger);
        config(['novon.deploy_webhook_secret' => 'geheim', 'novon.deploy_branch' => 'main']);
    }

    protected function tearDown(): void
    {
        File::delete($this->trigger);
        parent::tearDown();
    }

    private function send(array $payload, ?string $secret = 'geheim')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/deploy/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    public function test_valid_push_to_branch_creates_trigger(): void
    {
        $this->send(['ref' => 'refs/heads/main'])->assertStatus(202);
        $this->assertFileExists($this->trigger);
    }

    public function test_other_branch_is_ignored(): void
    {
        $this->send(['ref' => 'refs/heads/feature'])->assertStatus(202);
        $this->assertFileDoesNotExist($this->trigger);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->send(['ref' => 'refs/heads/main'], 'fout')->assertForbidden();
        $this->assertFileDoesNotExist($this->trigger);
    }

    public function test_disabled_without_secret(): void
    {
        config(['novon.deploy_webhook_secret' => null]);
        $this->send(['ref' => 'refs/heads/main'])->assertNotFound();
    }
}
