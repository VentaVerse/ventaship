<?php

namespace App\Http\Controllers\Ui;

use App\Fulfillment\Dashboard;
use App\Http\Controllers\Controller;
use App\Shipments\ShipmentStatuses;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ShipmentStatuses $statuses): View
    {
        $dashboard = new Dashboard;

        return view('dashboard.index', [
            'kpis' => $dashboard->kpis(),
            'trend' => $dashboard->trend(),
            'statuses' => $dashboard->byStatus(),
            'recent' => $dashboard->recent(),
            'couriers' => $dashboard->couriers(),
            'needs' => $dashboard->needs($statuses),
        ]);
    }
}
