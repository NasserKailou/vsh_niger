<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Billing\InvoiceController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:invoices.read'];
        $manage = ['permission:invoices.manage'];
        $issue = ['permission:invoices.issue'];
        $router->get('/invoices', [InvoiceController::class, 'index'], $read);
        $router->get('/invoices/summary', [InvoiceController::class, 'summary'], $read);
        $router->post('/invoices', [InvoiceController::class, 'store'], $manage);
        $router->get('/invoices/{id:uuid}', [InvoiceController::class, 'show'], $read);
        $router->get('/invoices/{id:uuid}/pdf', [InvoiceController::class, 'pdf'], $read);
        $router->put('/invoices/{id:uuid}', [InvoiceController::class, 'update'], $manage);
        $router->post('/invoices/{id:uuid}/items', [InvoiceController::class, 'addItem'], $manage);
        $router->delete('/invoices/{id:uuid}/items/{itemId:uuid}', [InvoiceController::class, 'removeItem'], $manage);
        $router->post('/invoices/{id:uuid}/issue', [InvoiceController::class, 'issue'], $issue);
        $router->post('/invoices/{id:uuid}/cancel', [InvoiceController::class, 'cancel'], $issue);
        $router->post('/invoices/{id:uuid}/settlement', [InvoiceController::class, 'settlement'], ['permission:invoices.settlement_declare']);
    });
    $router->get('/me/patients/{id:uuid}/invoices', [InvoiceController::class, 'forOwnPatient'], ['auth', 'permission:self.invoices.read']);
    $router->get('/me/patients/{id:uuid}/invoices/{invoiceId:uuid}/pdf', [InvoiceController::class, 'pdfForOwnPatient'], ['auth', 'permission:self.invoices.read']);
};
