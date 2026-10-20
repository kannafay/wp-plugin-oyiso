<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** Streams authenticated REST replies while the same durable runner keeps saving checkpoints. */
final class Oyiso_Agent_Live
{
    /** @template T of array<string, mixed>
     * @param WP_REST_Request<T> $request
     */
    public static function requested(WP_REST_Request $request): bool
    {
        return str_contains(strtolower($request->get_header('accept') ?? ''), 'text/event-stream');
    }

    /** @template T of array<string, mixed>
     * @param WP_REST_Request<T> $request
     */
    public static function serve(bool $served, WP_HTTP_Response $response, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if ($served || $response->get_status() >= 300 || $request->get_method() !== 'POST' || !self::requested($request) || !preg_match('#^/oyiso-agent/v1/runs(?:/\d+/step)?$#', $request->get_route())) return $served;
        $data = $response->get_data();
        $id = is_array($data) && is_int($data['id'] ?? null) ? $data['id'] : 0;
        if (!current_user_can('manage_options') || !Oyiso_Agent_Store::owned($id)) return $served;
        $server->send_header('Content-Type', 'text/event-stream; charset=utf-8');
        $server->send_header('Cache-Control', 'no-cache, no-store, no-transform');
        $server->send_header('X-Accel-Buffering', 'no');
        $abort = ignore_user_abort(true);
        if (PHP_SAPI !== 'cli') {
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) { if (!@ob_end_flush()) break; }
        }
        $emit = static function (string $event, array $payload): void {
            if (connection_aborted()) return;
            echo 'event: ' . $event . "\ndata: " . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            if (PHP_SAPI !== 'cli') flush();
        };
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) return true;
            $emit('state', Oyiso_Agent_Store::view($run));
            $deadline = microtime(true) + 80;
            $runner = new Oyiso_Agent_Runner(new Oyiso_Agent_Model());
            do {
                if (!in_array($run['status'], ['queued', 'running'], true)) break;
                $step = $run['step'];
                $runner->step($id, static function (string $delta, int $step, string $model) use ($emit, $id): void { $emit('text', ['id' => $id, 'step' => $step, 'model' => $model, 'delta' => $delta]); });
                $run = Oyiso_Agent_Store::get($id);
                if (is_wp_error($run)) { $emit('error', ['message' => '对话已不可用，请重新打开对话。']); return true; }
                $emit('state', Oyiso_Agent_Store::view($run));
            } while ($run['status'] === 'queued' && $run['step'] !== $step && microtime(true) < $deadline);
            if ($run['status'] === 'queued') Oyiso_Agent_Runner::enqueue($id);
            $emit('done', Oyiso_Agent_Store::view($run));
        } finally {
            ignore_user_abort($abort !== 0);
        }
        return true;
    }
}
