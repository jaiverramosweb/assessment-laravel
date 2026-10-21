<?php

namespace App\Http\Controllers\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Services\Clients\ClientInUseError;
use App\Services\Clients\ClientService;
use App\Services\Shared\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Client list, creation, page, rename and deletion. */
class ClientController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Clients/Index', ['clients' => ClientService::list()]);
    }

    public function store(Request $request)
    {
        [$name, $error] = ClientService::parseName($request->input('name'));
        if (! $error && ClientService::nameTaken($name)) {
            $error = ClientService::NAME_TAKEN;
        }
        if ($error) {
            return back(303)->withErrors(['name' => $error])->withInput(['name' => (string) $request->input('name')]);
        }

        $client = ClientService::create($name, $request->attributes->get('admin')->id, $request->ip());

        return redirect("/admin/clientes/{$client->id}?creado=1", 303);
    }

    public function show(string $id)
    {
        $c = ClientService::require($id);

        return Inertia::render('Admin/Clients/Show', [
            // Without the image: the logo is requested separately.
            'client' => [
                'id' => $c->id,
                'name' => $c->name,
                'themePrimary' => $c->theme_primary,
                'themeAccent' => $c->theme_accent,
                'logoUrl' => Brand::clientLogoUrl($c),
            ],
            'assessments' => ClientService::assessments($c->id),
        ]);
    }

    public function rename(Request $request, string $id)
    {
        $c = ClientService::require($id);
        [$name, $error] = ClientService::parseName($request->input('name'));
        if (! $error && ClientService::nameTaken($name, $c->id)) {
            $error = ClientService::NAME_TAKEN;
        }
        if ($error) {
            return back(303)->withErrors(['name' => $error])->withInput(['name' => (string) $request->input('name')]);
        }

        ClientService::rename($c->id, $name, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', 'Nombre guardado.');
    }

    public function destroy(Request $request, string $id)
    {
        $c = ClientService::require($id);
        try {
            ClientService::delete($c, $request->attributes->get('admin')->id, $request->ip());
        } catch (ClientInUseError $e) {
            return back(303)->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect('/admin/clientes?eliminado=1', 303);
    }
}
