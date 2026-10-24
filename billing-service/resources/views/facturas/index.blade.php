@extends('layouts.app')

@section('content')
    <div class="panel">
        <div class="panel-header">
            <h1 style="margin: 0;">Mis facturas</h1>
            <p style="margin: 6px 0 0; color: var(--muted);">Autenticado vía user-service · Sesión propia en este microservicio</p>
        </div>
        <div class="panel-body">
            <table>
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Importe</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td>#{{ $invoice['id'] }}</td>
                            <td>{{ number_format($invoice['amount'], 2) }} €</td>
                            <td>
                                <span class="badge {{ $invoice['status'] === 'paid' ? 'paid' : 'pending' }}">
                                    {{ $invoice['status'] === 'paid' ? 'Pagada' : 'Pendiente' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
