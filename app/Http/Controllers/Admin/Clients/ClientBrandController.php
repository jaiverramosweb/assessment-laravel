<?php

namespace App\Http\Controllers\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Services\Clients\ClientService;
use App\Services\Clients\LogoError;
use Illuminate\Http\Request;

/** Client logo and colors (the brand its assessments inherit). */
class ClientBrandController extends Controller
{
    /** Opening the image directly must not be able to run anything. */
    public const LOGO_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /** Admin logo (the admin middleware already requires a session for /admin). */
    public function showLogo(string $id)
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            abort(404, 'No encontrado');
        }
        $logo = ClientService::readLogo($id) ?? abort(404, 'Sin logo');

        return response($logo['data'], 200, [
            'Content-Type' => $logo['mime'],
            'Content-Security-Policy' => self::LOGO_CSP,
        ]);
    }

    public function uploadLogo(Request $request, string $id)
    {
        $c = ClientService::require($id);
        $file = $request->file('logo');
        if (is_array($file)) {
            $file = null;
        }
        if ($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->logoError('El logo no puede superar 2 MB.');
        }
        if (! $file || ! $file->isValid() || $file->getSize() === 0) {
            return $this->logoError('Seleccione una imagen.');
        }
        if ($file->getSize() > ClientService::LOGO_MAX_BYTES) {
            return $this->logoError('El logo no puede superar 2 MB.');
        }

        try {
            ClientService::setLogo($c->id, (string) file_get_contents($file->getRealPath()), $request->attributes->get('admin')->id, $request->ip());
        } catch (LogoError $e) {
            return $this->logoError($e->getMessage());
        }

        return back(303)->with('success', 'Logo actualizado.');
    }

    public function removeLogo(Request $request, string $id)
    {
        $c = ClientService::require($id);
        ClientService::setLogo($c->id, null, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', 'Logo eliminado.');
    }

    public function saveColors(Request $request, string $id)
    {
        $c = ClientService::require($id);
        [$theme, $errors] = ClientService::parseTheme($request->only(['primary', 'accent']));
        if ($errors) {
            return back(303)->withErrors($errors);
        }
        ClientService::setTheme($c->id, $theme, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', 'Colores guardados.');
    }

    public function resetColors(Request $request, string $id)
    {
        $c = ClientService::require($id);
        ClientService::setTheme($c->id, null, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', 'Se restablecieron los colores de la plataforma.');
    }

    private function logoError(string $message)
    {
        return back(303)->withErrors(['logo' => $message]);
    }
}
