<?php
/**
 * WS 实时层 · web 侧 nudge 加速器（P2）
 *
 * 发消息 / 撤回发生在 php-cgi 进程里，这里用一次极短的本机连接告诉守护进程
 * 「有新料 / 有撤回」，把推送延迟从 watcher 的 ≤1 秒压到毫秒级。
 *
 * 定位就是加速器，所以：
 *   - 只写不读：请求写完立即关连接（TCP 会把缓冲交给对端），不等响应、不阻塞发送路径；
 *   - 端口与共享密钥都从 data/ws-ctl.key 读（守护进程启动时写；文件不在 = 守护从没跑过 → 直接返回）；
 *   - 任何失败静默——丢失的后果只是慢 1 秒（守护进程每 tick 水位探询兜底），
 *     绝不冒泡、绝不影响发消息。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问

class RealtimePush
{
    /** 发一条 nudge；$payload 形如 {"room":5} / {"dm":"user:12"} / {"recall":9,...} */
    private static function send(array $payload): void
    {
        try {
            $ctl = @file_get_contents(__DIR__ . '/../data/ws-ctl.key');
            if (!is_string($ctl) || $ctl === '') return;
            $info = json_decode($ctl, true);
            if (!is_array($info) || empty($info['port']) || empty($info['key'])) return;
            $fp = @fsockopen('127.0.0.1', (int)$info['port'], $eNo, $eStr, 0.3);
            if (!$fp) return;   // 守护没在跑：本机拒绝极快，无副作用
            $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
            $req = "POST /ctl/nudge HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Ow-Ctl: " . (string)$info['key']
                 . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
                 . "\r\nConnection: close\r\n\r\n" . $body;
            @fwrite($fp, $req);
            @fclose($fp);   // 只写不读：请求已进对端缓冲，关闭连接不影响投递
        } catch (Throwable $e) { /* 加速器，绝不冒泡 */ }
    }

    /** 群聊新消息 */
    public static function nudgeRoom(int $roomId): void
    {
        if ($roomId > 0) self::send(['room' => $roomId]);
    }

    /** 私聊新消息（peer 形如 user:12——v1.1.2 起私聊只有注册用户之间） */
    public static function nudgeDm(string $peer): void
    {
        if ($peer !== '') self::send(['dm' => $peer]);
    }

    /**
     * 撤回：room_id>0 推房间，否则推私聊对；前端把气泡变「已撤回」占位。
     * ⚠️ 私聊必须**双向各推一条**：座位的订阅 key 是「本会话里的对方」——
     *   发送方的 dms 键是 to_user_id、接收方的键是 user_id（from）。
     *   只推一个方向时另一侧收不到帧，气泡要等刷新才消失（与撤回的初衷相反）。
     */
    public static function nudgeRecall(int $msgId, int $roomId, int $fromUserId, int $toUserId): void
    {
        if ($msgId <= 0) return;
        if ($roomId > 0) { self::send(['recall' => $msgId, 'room' => $roomId]); return; }
        if ($fromUserId > 0) self::send(['recall' => $msgId, 'dm' => 'user:' . $fromUserId]);
        if ($toUserId > 0 && $toUserId !== $fromUserId) self::send(['recall' => $msgId, 'dm' => 'user:' . $toUserId]);
    }
}
