<div style="font-family: 'Times New Roman', Arial, sans-serif; padding: 35px; max-width: 750px; margin: 0 auto; color: #1a1a1a; border: 1px solid #ccc; border-radius: 6px;">
    <div style="text-align: center; margin-bottom: 25px;">
        <h2 style="font-size: 20px; font-weight: bold; margin: 0 0 8px 0; color: #111;">ІНФОРМАЦІЙНА ДОВІДКА<br>ЕЛЕКТРОННОГО РЕЦЕПТА (ПАМ'ЯТКА)</h2>
        <div style="font-size: 15px; font-weight: bold;">Номер рецепта в ЕСОЗ: <span style="color: #1e3a8a; font-size: 18px;">{{ $requestNumber }}</span></div>
    </div>
    <hr style="border-top: 1px solid #ddd; margin: 20px 0;">
    <table style="width: 100%; font-size: 15px; border-collapse: collapse; line-height: 1.5;">
        <tr>
            <td style="padding: 8px 10px; width: 35%; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Пацієнт:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{{ $patientName }} (р.н.: {{ $patientBirthDate }})</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Медична програма:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{{ $programName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Лікарський засіб (МНН / Назва):</td>
            <td style="padding: 8px 10px; font-weight: bold; color: #0f3460; border-bottom: 1px solid #f0f0f0;">{{ $medicationName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Призначена кількість:</td>
            <td style="padding: 8px 10px; font-weight: bold; border-bottom: 1px solid #f0f0f0;">{{ $medicationQty }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Спосіб вживання (Сигнатура):</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{{ $dosageText }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Термін дії рецепту:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">з <strong>{{ $startDate }}</strong> по <strong>{{ $endDate }}</strong></td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Код підтвердження (погашення):</td>
            <td style="padding: 8px 10px; font-weight: bold; color: #b91c1c; border-bottom: 1px solid #f0f0f0;">{{ $otpInfo }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Лікар та заклад:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{{ $doctorName }}<br><span style="font-size: 13px; color: #555;">{{ $facilityName }}</span></td>
        </tr>
    </table>
    <div style="margin-top: 30px; padding: 15px; background-color: #f8fafc; border-left: 4px solid #3b82f6; font-size: 13px; color: #334155; line-height: 1.4;">
        <strong>Увага для аптеки та пацієнта:</strong><br>
        Для отримання лікарського засобу назвіть фармацевту в аптеці 16-значний номер рецепту в ЕСОЗ та код підтвердження з SMS-повідомлення. Рецепт може бути погашений у будь-якій аптеці України, що уклала договір з НСЗУ за відповідною програмою або відпускає електронні рецепти.
    </div>
</div>
