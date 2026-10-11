<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * GitHub-webhook voor automatisch uitrollen. Controleert de HMAC-handtekening en zet alleen een
 * triggerbestand neer; systemd (deploy/novon-deploy.path) voert daarna deploy.sh uit.
 */
class DeployWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = config('novon.deploy_webhook_secret');
        abort_if(blank($secret), 404);

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403);

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response('pong');
        }

        $branch = config('novon.deploy_branch');
        if ($request->input('ref') !== "refs/heads/{$branch}") {
            return response("Genegeerd: niet {$branch}.", 202);
        }

        File::put(storage_path('app/deploy.trigger'), now()->toIso8601String());

        return response('Deploy gestart.', 202);
    }
}
