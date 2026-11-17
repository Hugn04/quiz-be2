<?php

namespace App\Support;

use RuntimeException;

/**
 * Chống SSRF khi server tự tải link do người dùng nhập (quét ảnh hỏng, chuyển ảnh về Cloudinary):
 * chỉ cho http(s), cổng 80 / 443, và host phải trỏ tới IP CÔNG KHAI (không localhost, mạng LAN,
 * 169.254.x metadata cloud, dải dành riêng). Mỗi lần chuyển hướng (redirect) được kiểm tra lại.
 */
class SafeUrl
{
    /** Giới hạn dung lượng phản hồi khi kiểm tra ảnh (byte). */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function isPublicHttp(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tuỳ chọn Guzzle (Http::withOptions) an toàn: kiểm tra lại từng bước redirect và dừng khi
     * phản hồi quá lớn.
     */
    public static function guzzleOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    if (!self::isPublicHttp((string) $uri)) {
                        throw new RuntimeException('Chuyển hướng tới địa chỉ không được phép.');
                    }
                },
            ],
            'on_headers' => function ($response) {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new RuntimeException('Phản hồi quá lớn.');
                }
            },
        ];
    }

    /** IPv4 + IPv6 của host (rỗng nếu không phân giải được). */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
