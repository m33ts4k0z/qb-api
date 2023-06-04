<?php

namespace App\Http\Controllers;

use Hash;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class QBApiInterface extends Controller
{
    private $ttl, $baseURL, $token;

    public function __construct(Request $request) {
        // Use cache only on live
        $this->ttl = env('APP_ENV', 'production') === 'production' ? now()->addHour() : 0;
        $this->baseURL= env('QB_BASE_URL', 'https://api.quickbutik.com/v1');
        $this->token = env('QB_TOKEN', null);        
    }

    public function index(Request $request)
    {
        $token = auth()->user()->createToken(now())->plainTextToken;
        return view('welcome', compact('token'));
    }

    public function getAverage(Request $request) {   
        $allOrders = $this->getOrders(); 

        // Check if the HTTP request failed. This should never happen when the website is in production
        if(!$allOrders) 
            return "An error occured. Please try again later";

        if(count($allOrders) > 0) {
            // Get the average price of the orders        
            return round($allOrders->avg('total_amount'));
        }
        else {
            // Throw an error if no orders are returned
            info('No orders found: ' . $filteredOrders);
            return "No orders found.";
        }
    }

    public function getTopsellers(Request $request) {

        // Get every order with its products
        $requestResult = $this->getOrderData();

        // Check if the HTTP request failed. This should never happen when the website is in production
        if(!$requestResult) 
            return "An error occured. Please try again later";
        
        $filteredOrders = $requestResult;

        if(count($filteredOrders) > 0)
        {
            // Get all products from the orders
            $productCount = $filteredOrders->map(function ($index)  {
                return $index->map(function($i) {
                    return $i['products'];
                });
            });
            
            $allProducts = [];

            // Gather all the products to a usable collection
            $allProducts[] = data_get($productCount->toArray(), '*.0.*');
                        
            // Get count for each product
            $topProducts = collect($allProducts[0])->countBy(function($product) {
                return $product['title'];
            });

            // Show the top 3 products
            return $topProducts->sortDesc()->take(3);
        }
        else
        {
            // Throw an error if no order data are returned
            info('No order data found: ' . $filteredOrders);
            return "No order data found.";
        }            
    }    

    public function getOrders() {             
        // Get all the available orders      
        return Cache::remember('quickbutik.orders', $this->ttl, function() {
            if($this->token) {
                $ordersRequest = Http::withBasicAuth($this->token, $this->token)
                ->get($this->baseURL . '/orders');

                if($ordersRequest->successful())                   
                    return $ordersRequest->collect();                   
                else {
                    info('Failed to get orders:');
                    info($orderDataRequest['error']);
                    return false;
                }                  
            }
                info('Token error: ' . $this->token);
                return "Invalid token. Please check that the QB_TOKEN value in the .env file is set and try again.";
        });
    }

    public function getOrderData() {
        $orderData = [];

        // Check token
        if($this->token) {
            // Use cache to accelerate the process 
            return Cache::remember('quickbutik.orderdata', $this->ttl, function() {
                foreach($this->getOrders() as $order)
                {                        
                    $orderDataRequest = Http::withBasicAuth($this->token, $this->token)
                    ->get($this->baseURL . '/orders', ['order_id' => $order['order_id']]);
        
                    if($orderDataRequest->successful())                   
                        $orderData[] =  $orderDataRequest->collect();                    
                    else {
                        info("Failed to get order data:");
                        info($orderDataRequest['error']);
                        return false;
                    }                
                }
                return collect($orderData);
            });
        }
            info('Token error: ' . $this->token);
            return "Invalid token. Please check that the QB_TOKEN value in the .env file is set and try again.";       
    }
}
