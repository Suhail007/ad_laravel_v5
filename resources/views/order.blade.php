{{-- <!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Confirmation</title>
</head>
<body>
  <div style="margin-top: 100px; display: flex; justify-content: center;">
    <div style=" width:50%; margin-left: auto; margin-right: auto;">
      <div style="
            height: 100%; 
            margin: 0; 
            border-radius: 0px 0px 10px 10px; 
            background: var(--white, #FFF); 
            box-shadow: 0px 3.5396px 8.84901px 0px rgba(0, 0, 0, 0.10);  padding: 40px;">
                      
      <div style="margin-left: 5px;">
        <div style="display: flex; align-items: center;">
          <div>
            <img src="https://ad.phantasm.solutions/wp-content/uploads/2024/03/ad_logo.png" class="img-fluid p-3" />
          </div>
          <div style="text-align: end; flex-grow: 1;">
            <h4 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins, sans-serif; font-size: 22.75px; font-style: normal; font-weight: 500; line-height: normal;">Order Confirmed</h4>

            <h4 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 22.75px; font-style: normal; font-weight: 600; line-height: normal;">Order # <span style="color: var(--Text-blue, #119AD5); font-family: Poppins; font-size: 22.75px; font-style: normal; font-weight: 600; line-height: normal;">{{ $orderNumber}}</span></h4>

          </div>
        </div>
        <div style="padding: 10px; margin-top: 10px;">
          <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins, sans-serif; font-size: 22.75px; font-style: normal; font-weight: 500; line-height: normal;">
            Hello {{ $username}},
          </h5>
          <h6 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;">
            Thank you for shopping with us. We’d like to let you know that American Distributors has received your order, and is preparing it for shipment. Your estimated delivery date is below. If you would like to view the status of your order or make any changes to it, please visit Your Orders on American Distributors. LLC
          </h6>
        </div>
        <br />
        <br />
        <br />
      </div>
      <div style="margin: 0 auto; padding: 20px; width: 75%; height: 230px; flex-shrink: 0; border-radius: 10px; border-top: 5px solid var(--Text-blue, #119AD5); background: var(--blue-5, rgba(77, 175, 230, 0.05));">
        <div style="display: flex; flex-direction: column; align-items: center;">
          <div style="display: flex; gap: 20px;">
            <div style="flex: 1;">
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;">Your estimated delivery date is:</h5>
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;"><b>{{ $date}}</b></h5>
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;">Your shipping speed</h5>
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;">Standard Shipping</h5>
            </div>
            <div style="flex: 1;">
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;">Your order will be sent to</h5>
              <h5 style="color: var(--theme-color-dark-blue, #2D3845); font-family: Poppins; font-size: 14px; font-style: normal; font-weight: 400; line-height: normal;"> <b>{{ $orderaddress}}</b></h5>
            </div>
          </div>
         <a href="https://express.americandistributorsllc.com/myaccount?tab=Orders">
          <button style="width: 238px; height: 40px; flex-shrink: 0; border-radius: 5px; background: var(--Navy-gradient, linear-gradient(90deg, #094C89 1.15%, #019EF7 110.67%)); color: var(--white, #FFF); font-feature-settings: 'clig' off, 'liga' off; font-family: Roboto; font-size: 14px; font-style: normal; font-weight: 500; line-height: 19.009px; border:none;">
            Order Details
          </button>      
        </a>                                                   
        </div>
      </div>
    </div>
  </div>
</body>
</html> --}}




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Poppins, sans-serif;
            background-color: #f4f4f4;
            color: #2D3845;
        }
        .container {
            max-width: 600px;
            margin: 100px auto;
            background-color: #ffffff;
            box-shadow: 0 3.54px 8.85px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
            padding: 20px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .header img {
            max-width: 120px;
        }
        .title {
            text-align: right;
        }
        .title h4 {
            margin: 0;
            color: #2D3845;
            font-size: 22px;
        }
        .order-number {
            color: #119AD5;
            font-weight: bold;
        }
        .message {
            margin-bottom: 20px;
        }
        .details {
            background-color: rgba(77, 175, 230, 0.05);
            border-top: 5px solid #119AD5;
            padding: 15px;
            border-radius: 5px;
        }
        .details h5 {
            margin: 5px 0;
            font-size: 14px;
        }
        .details b {
            color: #119AD5;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(90deg, #094C89 1.15%, #019EF7 110.67%);
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            text-align: center;
            margin-top: 15px;
            display: block;
            width: 100%;
        }
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            .header img {
                max-width: 100px;
            }
            .title h4 {
                font-size: 18px;
            }
            .details h5 {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
           <img src="https://ad.phantasm.solutions/wp-content/uploads/2024/03/ad_logo.png" alt="Logo">
            <div class="title">
                <h4>Order Confirmed</h4>
                <h4>Order # <span class="order-number">{{ $orderNumber }}</span></h4>
            </div>
        </div>
        <div class="message">
            <h5>Hello {{ $username }},</h5>
            <h6>Thank you for shopping with us. We’d like to let you know that American Distributors has received your order and is preparing it for shipment. Your estimated delivery date is below. If you would like to view the status of your order or make any changes to it, please visit Your Orders on American Distributors, LLC.</h6>
        </div>
        <div class="details">
            <div>
                <h5>Your estimated delivery date is:</h5>
                <h5><b>{{ $date }}</b></h5>
                <h5>Your shipping speed:</h5>
                <h5>Standard Shipping</h5>
            </div>
            <div>
                <h5>Your order will be sent to:</h5>
                <h5><b>{{ $orderaddress }}</b></h5>
            </div>
            <a href="https://express.americandistributorsllc.com/myaccount?tab=Orders" class="cta-button">
                Order Details
            </a>
        </div>
    </div>
</body>
</html>
