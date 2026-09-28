<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #222;">
    <h2>Pawzo</h2>

    @if ($type === 'password_reset')
        <p>Use this code to reset your password:</p>
    @else
        <p>Use this code to verify your account:</p>
    @endif

    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>

    <p>This code expires in {{ $expiryMinutes }} minutes. If you didn't request it, you can ignore this email.</p>
</body>
</html>