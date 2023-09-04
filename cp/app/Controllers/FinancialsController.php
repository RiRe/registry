<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class FinancialsController extends Controller
{
    public function transactions(Request $request, Response $response)
    {
        return view($response,'admin/financials/transactions.twig');
    }
	
    public function overview(Request $request, Response $response)
    {
        return view($response,'admin/financials/overview.twig');
    }
	
    public function pricing(Request $request, Response $response)
    {
        return view($response,'admin/financials/pricing.twig');
    }
}