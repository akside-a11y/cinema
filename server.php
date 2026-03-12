<?php
/**
 * CineSync — WebSocket Server
 * Run with: php server.php
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
    private array $rooms = [];   // roomId => [ connId => { conn, userId, userName } ]

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
            case 'load_video':
            case 'chat':
            case 'reaction':
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

    // ── Helpers ──────────────────────────────────
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
        $this->broadcastToRoom($roomId, [
            'type'        => 'joined',
            'roomId'      => $roomId,
            'userId'      => $userId,
            'userName'    => $userName,
            'viewerCount' => $count,
        ], null); // broadcast to ALL including self so viewer count updates
    }

    private function broadcastToRoom(string $roomId, array $data, ?ConnectionInterface $skip = null) {
        if (!isset($this->rooms[$roomId])) return;
        $json = json_encode($data);
        foreach ($this->rooms[$roomId] as $peer) {
            if ($skip && $peer['conn'] === $skip) continue;
            try { $peer['conn']->send($json); } catch (\Exception $e) {}
        }
    }

    private function sendToUser(string $roomId, string $targetUserId, array $data) {
        if (!isset($this->rooms[$roomId])) return;
        $json = json_encode($data);
        foreach ($this->rooms[$roomId] as $peer) {
            if ($peer['userId'] === $targetUserId) {
                try { $peer['conn']->send($json); } catch (\Exception $e) {}
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

                if ($count === 0) {
                    unset($this->rooms[$roomId]);
                }
            }
        }
    }
}

// ── Port selection with auto-fallback ────────────────────────────────
// XAMPP uses 8080 for Tomcat. We try several ports automatically.
$requestedPort = (int)($argv[1] ?? 0);
$candidates    = $requestedPort > 0
    ? [$requestedPort]
    : [8181, 8282, 8383, 7777, 9090];

$port   = null;
$server = null;

foreach ($candidates as $try) {
    if (!portInUse($try)) {
        $port = $try;
        break;
    }
    echo "[CineSync] Port {$try} is busy, trying next...\n";
}

if ($port === null) {
    echo "[CineSync] ERROR: All candidate ports are in use.\n";
    echo "           Run:  php server.php <free-port>  (e.g. php server.php 8484)\n";
    exit(1);
}

try {
    $server = IoServer::factory(
        new HttpServer(new WsServer(new CineSyncServer())),
        $port
    );
} catch (\RuntimeException $e) {
    echo "[CineSync] ERROR: Could not bind to port {$port}: {$e->getMessage()}\n";
    echo "           Try:  php server.php <another-port>\n";
    exit(1);
}

// ── Write chosen port to a file so the frontend JS can read it ───────
file_put_contents(__DIR__ . '/ws_port.txt', $port);

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║         CineSync WebSocket Server        ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  WS  →  ws://localhost:{$port}              \n";
echo "║                                          ║\n";
echo "║  Frontend:  http://localhost             ║\n";
echo "║  (open in browser while XAMPP is on)     ║\n";
echo "╚══════════════════════════════════════════╝\n\n";
echo "[CineSync] Press Ctrl+C to stop.\n\n";

$server->run();

// ── Helper ───────────────────────────────────────────────────────────
function portInUse(int $port): bool {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($sock) { fclose($sock); return true; }
    return false;
}
