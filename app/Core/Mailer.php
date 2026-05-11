<?php
class Mailer {

    private static function readResp($sock): string {
        $data = '';
        while ($line = fgets($sock, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    }

    private static function cmd($sock, string $c): string {
        fwrite($sock, $c . "\r\n");
        return self::readResp($sock);
    }

    public static function send(string $to, string $toName, string $subject, string $html): true|string {
        $host      = Configuracao::get('smtp_host',       null, '');
        $port      = (int) Configuracao::get('smtp_port', null, '587');
        $user      = Configuracao::get('smtp_user',       null, '');
        $pass      = Configuracao::get('smtp_pass',       null, '');
        $fromName  = Configuracao::get('smtp_from_name',  null, defined('APP_NAME') ? APP_NAME : 'Sistema');
        $fromEmail = Configuracao::get('smtp_from_email', null, $user);
        $enc       = Configuracao::get('smtp_encryption', null, 'tls');

        if (!$host || !$user || !$pass) {
            return 'SMTP não configurado. Acesse Admin → E-mail.';
        }

        $ctx  = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);
        $pfx  = ($enc === 'ssl') ? 'ssl://' : 'tcp://';
        $sock = @stream_socket_client("{$pfx}{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

        if (!$sock) {
            return "Não foi possível conectar em {$host}:{$port}: {$errstr}";
        }
        stream_set_timeout($sock, 15);

        self::readResp($sock);
        self::cmd($sock, 'EHLO keekconecta');

        if ($enc === 'tls') {
            $r = self::cmd($sock, 'STARTTLS');
            if (!str_starts_with(trim($r), '220')) {
                fclose($sock);
                return 'STARTTLS falhou: ' . trim($r);
            }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock);
                return 'Falha ao ativar TLS.';
            }
            self::cmd($sock, 'EHLO keekconecta');
        }

        self::cmd($sock, 'AUTH LOGIN');
        self::cmd($sock, base64_encode($user));
        $authR = self::cmd($sock, base64_encode($pass));

        if (!str_starts_with(trim($authR), '235')) {
            fclose($sock);
            return 'Autenticação SMTP falhou: ' . trim($authR);
        }

        self::cmd($sock, "MAIL FROM:<{$fromEmail}>");
        self::cmd($sock, "RCPT TO:<{$to}>");
        self::cmd($sock, 'DATA');

        // Dot-stuffing: escape lines that start with '.'
        $body = implode("\r\n", array_map(
            fn($l) => str_starts_with($l, '.') ? '.' . $l : $l,
            explode("\n", str_replace("\r\n", "\n", $html))
        ));

        $toHdr   = $toName   ? '=?UTF-8?B?' . base64_encode($toName)   . "?= <{$to}>"        : "<{$to}>";
        $fromHdr = $fromName ? '=?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromEmail}>" : "<{$fromEmail}>";
        $subjHdr = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $msg  = 'Date: ' . date('r') . "\r\n";
        $msg .= "From: {$fromHdr}\r\n";
        $msg .= "To: {$toHdr}\r\n";
        $msg .= "Subject: {$subjHdr}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: 8bit\r\n";
        $msg .= "\r\n{$body}\r\n.\r\n";

        fwrite($sock, $msg);
        $sendR = self::readResp($sock);
        self::cmd($sock, 'QUIT');
        fclose($sock);

        if (!str_starts_with(trim($sendR), '250')) {
            return 'Envio falhou: ' . trim($sendR);
        }
        return true;
    }

    public static function defaultResetTemplate(): string {
        $app = defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Sistema';
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f5;margin:0;padding:32px">
<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:40px;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <h2 style="color:#111827;margin-top:0;margin-bottom:8px">Redefinição de Senha</h2>
  <p style="color:#6b7280;margin-bottom:24px;line-height:1.6">Olá, <strong>{{nome}}</strong>!<br>Recebemos uma solicitação para redefinir a senha da sua conta em {$app}.</p>
  <p style="margin-bottom:24px">
    <a href="{{link}}" style="display:inline-block;padding:13px 28px;background:#16a34a;color:#ffffff;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px">Redefinir minha senha</a>
  </p>
  <p style="color:#6b7280;font-size:13px;line-height:1.6">Este link é válido por <strong>{{expira}}</strong>.<br>Se você não solicitou a redefinição de senha, ignore este e-mail com segurança.</p>
  <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
  <p style="color:#9ca3af;font-size:12px;margin:0">Se o botão não funcionar, copie e cole o link abaixo no navegador:<br><span style="color:#2563eb;word-break:break-all">{{link}}</span></p>
</div>
</body>
</html>
HTML;
    }
}
