<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 30px;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            border: 1px solid #eee;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.15);
            padding: 30px;
        }
        .header {
            display: table;
            width: 100%;
            border-bottom: 3px solid #333;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .header-left { display: table-cell; }
        .header-right { display: table-cell; text-align: right; }
        
        .info { margin-bottom: 30px; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th {
            background-color: #333;
            color: white;
            padding: 10px;
            text-align: left;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        .total-section {
            margin-top: 30px;
            text-align: right;
            border-top: 2px solid #333;
            padding-top: 10px;
        }
        .total-amount {
            font-size: 1.5em;
            color: #000;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>INVOICE</h1>
            <p>Order ID: #{{ $id }}</p>
        </div>
        <div class="header-right">
            <p><strong>Date:</strong> {{ $date }}</p>
            <p><strong>Status:</strong> Paid</p>
        </div>
    </div>

    <div class="info">
        <p><strong>Customer Name:</strong> {{ $name }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $item->product->name }}</td>
                <td>{{ $item->quantity }}</td>
                <td>${{ number_format($item->price, 2) }}</td>
                <td>${{ number_format($item->price * $item->quantity, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-section">
        <p>Grand Total</p>
        <div class="total-amount">${{ number_format($total, 2) }}</div>
    </div>
</body>
</html>