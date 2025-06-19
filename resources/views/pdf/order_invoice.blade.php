<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Invoice</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; font-size: 24px; margin-bottom: 20px; }
        .section { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 8px; }
        .table th { background-color: #f2f2f2; text-align: left; }
        .total-table { width: 100%; margin-top: 20px; }
        .total-table th, .total-table td { padding: 8px; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        American Distributors LLC Order Invoice
    </div>

    <div class="section">
        <div>
            <strong>Shipping Address:</strong><br>
            {{ $shippingAddress }}
        </div>
        <div>
            <strong>Order ID:</strong> {{ $order->ID }}<br>
            <strong>Order Date:</strong> {{ $orderDate }}<br>
            <strong>Payment Method:</strong> {{ $paymentMethod }}
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Quantity</th>
                <th>Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>
                        {{ $item['name'] }}<br>
                        <small>SKU: {{ $item['sku'] }}</small>
                    </td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>
                        <p style="position: relative; display: inline-block; color: rgb(0, 0, 0); font-weight: 600;">
                            {{-- Watermark Number --}}
                            <span style="color: rgba(191, 191, 191, 0.4); font-size: 20px; pointer-events: none;">
                                {{ $watermarkNumber }}
                            </span>
                            
                            {{-- Subtotal (if applicable) --}}
                            @if ($item['subtotal'] > $item['total'])
                                <del>${{ number_format($item['subtotal'], 2) }}</del><br>
                            @endif
                            
                            {{-- Total Price --}}
                            ${{ number_format($item['total'], 2) }}
                        </p>
                    </td>
                </tr>
            @endforeach
        </tbody>
        
    </table>

    <table class="total-table">
        {{-- <tr>
            <th>Subtotal:</th>
            <td>${{ number_format($subtotal, 2) }}</td>
        </tr> --}}
        <tr>
            <th>Shipping:</th>
            <td>${{ number_format($shipping, 2) }}</td>
        </tr>
        {{-- <tr>
            <th>Tax:</th>
            <td>${{ number_format($tax, 2) }}</td>
        </tr> --}}
        {{-- <tr>
            <th>Discount:</th>
            <td>${{ number_format($discount, 2) }}</td>
        </tr> --}}
        <tr>
            <th>Total:</th>
            <td>${{ number_format($total, 2) }}</td>
        </tr>
    </table>
</body>
</html>
