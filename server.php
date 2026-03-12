<?php
/**
 * CineSync — WebSocket Server
 * Deployed on Railway — reads PORT from environment
 * Local fallback: php server.php (uses 8181)
 * Requires: composer require cboden/ratchet
 */

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class CineSyncServer implements MessageComponentInterface {
    private \SplObjectStorage $clients;
    private array $rooms = [];      // roomId => [ connId => { conn, userId, userName } ]
    private array $roomState = [];  // roomId => { videoUrl, title, time, paused, lastUpdated }

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[CineSync] WebSocket server starting...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "[+] Connection {$conn->resourceId} opened\n";
    }

    public function onMessage(ConnectionInterface $from, $rawMsg) {
        $data = json_decode($rawMsg, true);
        if (!$data) return;

        $type     = $data['type']     ?? '';
        $roomId   = $data['roomId']   ?? '';
        $userId   = $data['userId']   ?? '';
        $userName = $data['userName'] ?? 'Guest';

        switch ($type) {
            case 'join':
                $this->handleJoin($from, $roomId, $userId, $userName);
                break;

            case 'video_state':
                // Update server-side room state so future joiners get current position
                if ($roomId && isset($data['time'])) {
                    if (!isset($this->roomState[$roomId])) $this->roomState[$roomId] = [];
                    $this->roomState[$roomId]['time']        = (float)$data['time'];
                    $this->roomState[$roomId]['paused']      = ($data['action'] === 'pause');
                    $this->roomState[$roomId]['lastUpdated'] = microtime(true);
                }
                $this->broadcastToRoom($roomId, $data, $from);
                break;

            case 'load_video':
                // Store video URL so late joiners auto-load the same video
                if ($roomId) {
                    if (!isset($this->roomState[$roomId])) $this->roomState[$roomId] = [];
                    $this->roomState[$roomId]['videoUrl']    = $data['url']   ?? '';
                    $this->roomState[$roomId]['title']       = $data['title'] ?? '';
                    $this->roomState[$roomId]['time']        = 0.0;
                    $this->roomState[$roomId]['paused']      = true;
                    $this->roomState[$roomId]['lastUpdated'] = microtime(true);
                }
                $this->broadcastToRoom($roomId, $data, $from);
                break;

            case 'chat':
            case 'reaction':
            case 'subtitles':
            case 'webrtc_ready':
            case 'webrtc_offer':
            case 'webrtc_answer':
            case 'webrtc_ice':
            case 'webrtc_leave':
                $this->broadcastToRoom($roomId, $data, $from);
                break;

            case 'sync_to_new':
                $this->sendToUser($roomId, $data['targetId'] ?? '', $data);
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        $this->removeFromRooms($conn);
        echo "[-] Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[!] Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function handleJoin(ConnectionInterface $conn, string $roomId, string $userId, string $userName) {
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [];
        }

        $this->rooms[$roomId][$conn->resourceId] = [
            'conn'     => $conn,
            'userId'   => $userId,
            'userName' => $userName,
        ];

        $count = count($this->rooms[$roomId]);

        // Broadcast to ALL in room (including self) so viewer count updates everywhere
        $this->broadcastToRoom($roomId, [
            'type'        => 'joined',
            'roomId'      => $roomId,
            'userId'      => $userId,
            'userName'    => $userName,
            'viewerCount' => $count,
        ], null);

        echo "[CineSync] User '{$userName}' joined room '{$roomId}' ({$count} watching)\n";

        // ── Send room state snapshot directly to the new joiner ──────
        // Fires immediately — no waiting for the host to notice and respond
        $state = $this->roomState[$roomId] ?? null;
        if ($state && !empty($state['videoUrl'])) {
            // 1. Tell the joiner which video to load
            $conn->send(json_encode([
                'type'   => 'load_video',
                'url'    => $state['videoUrl'],
                'title'  => $state['title'] ?? '',
                'userId' => '__server__',
            ]));

            // 2. Estimate current playback position (account for time elapsed)
            $estimatedTime = $state['time'] ?? 0.0;
            if (!($state['paused'] ?? true) && isset($state['lastUpdated'])) {
                $estimatedTime += microtime(true) - $state['lastUpdated'];
            }

            // 3. Tell the joiner where to seek + play/pause state
            $conn->send(json_encode([
                'type'   => 'video_state',
                'action' => ($state['paused'] ?? true) ? 'pause' : 'play',
                'time'   => round($estimatedTime, 2),
                'userId' => '__server__',
            ]));

            echo "[CineSync] Sent room snapshot to '{$userName}' (t={$estimatedTime})\n";
        }
    }

    private function broadcastToRoom(string $roomId, array $data, ?ConnectionInterface $skip = null) {
        if (!isset($this->rooms[$roomId])) return;
        $json = json_encode($data);
        foreach ($this->rooms[$roomId] as $peer) {
            if ($skip && $peer['conn'] === $skip) continue;
            try {
                $peer['conn']->send($json);
            } catch (\Exception $e) {
                echo "[!] Send error: {$e->getMessage()}\n";
            }
        }
    }

    private function sendToUser(string $roomId, string $targetUserId, array $data) {
        if (!isset($this->rooms[$roomId])) return;
        $json = json_encode($data);
        foreach ($this->rooms[$roomId] as $peer) {
            if ($peer['userId'] === $targetUserId) {
                try {
                    $peer['conn']->send($json);
                } catch (\Exception $e) {
                    echo "[!] Send error: {$e->getMessage()}\n";
                }
                break;
            }
        }
    }

    private function removeFromRooms(ConnectionInterface $conn) {
        foreach ($this->rooms as $roomId => &$members) {
            if (isset($members[$conn->resourceId])) {
                $info  = $members[$conn->resourceId];
                unset($members[$conn->resourceId]);
                $count = count($members);

                $this->broadcastToRoom($roomId, [
                    'type'        => 'left',
                    'roomId'      => $roomId,
                    'userId'      => $info['userId'],
                    'userName'    => $info['userName'],
                    'viewerCount' => $count,
                ]);

                echo "[CineSync] User '{$info['userName']}' left room '{$roomId}' ({$count} watching)\n";

                if ($count === 0) {
                    unset($this->rooms[$roomId]);
                    unset($this->roomState[$roomId]);
                    echo "[CineSync] Room '{$roomId}' is now empty and removed.\n";
                }
            }
        }
    }
}

// ── Port Configuration ────────────────────────────────────────────────────────
// Railway injects a dynamic PORT environment variable.
// Locally (XAMPP / CLI) it falls back to 8181.
$port = (int)(getenv('PORT') ?: ($argv[1] ?? 8181));

echo "[CineSync] Binding to 0.0.0.0:{$port}\n";

try {
    $server = IoServer::factory(
        new HttpServer(new WsServer(new CineSyncServer())),
        $port,
        '0.0.0.0'   // Must be 0.0.0.0 so Railway's proxy can reach it
    );
} catch (\RuntimeException $e) {
    echo "[CineSync] ERROR: Could not bind to port {$port}: {$e->getMessage()}\n";
    echo "           Try:  php server.php <another-port>\n";
    exit(1);
}

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║      For V — Cinema Together Server     ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  Port  →  {$port}                           \n";
echo "║  Bind  →  0.0.0.0 (Railway-compatible)   ║\n";
echo "║                                          ║\n";
echo "║  Local WS  →  ws://localhost:{$port}        \n";
echo "╚══════════════════════════════════════════╝\n\n";
echo "[CineSync] Press Ctrl+C to stop.\n\n";

$server->run();
