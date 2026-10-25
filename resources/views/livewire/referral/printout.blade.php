<div style="font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; border: 1px solid #ccc; border-radius: 8px;">
    <h2 style="text-align: center; color: #1e3a8a;">ІНФОРМАЦІЙНА ДОВІДКА НАПРАВЛЕННЯ</h2>
    <p style="text-align: center; font-size: 14px; color: #555;">Електронне направлення № {{ $requisition }}</p>
    <div style="text-align: center; margin: 16px 0;">{!! $barcodeHtml !!}</div>
    <hr style="border-top: 1px solid #eee; margin: 20px 0;"/>
    <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
        <tr><td style="padding: 8px 0; font-weight: bold;">Тип:</td><td style="padding: 8px 0;">{{ $name }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Статус:</td><td style="padding: 8px 0;">{{ $statusLabel }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Пацієнт:</td><td style="padding: 8px 0;">{{ $patientName }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Код послуги/виробу:</td><td style="padding: 8px 0;">{{ $code }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Кількість:</td><td style="padding: 8px 0;">{{ $quantity }} од.</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Термін дії:</td><td style="padding: 8px 0;">з {{ \Carbon\Carbon::parse($startedAt)->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($endedAt)->format('d.m.Y') }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Лікар:</td><td style="padding: 8px 0;">{{ $employeeName }}</td></tr>
        <tr><td style="padding: 8px 0; font-weight: bold;">Примітки:</td><td style="padding: 8px 0;">{{ $note }}</td></tr>
    </table>
    <div style="margin-top: 40px; text-align: center; font-size: 12px; color: #888;">{{ $adviceText }}</div>
</div>
