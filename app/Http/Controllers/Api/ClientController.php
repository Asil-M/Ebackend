<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientProfileRequest;
use App\Http\Resources\ClientResource;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /** Return the authenticated user's client profile. */
    public function show(Request $request): ClientResource
    {
        // Stop with HTTP 404 when this user has not created a profile.
        abort_unless($request->user()->client, 404);

        return new ClientResource($request->user()->client);
    }

    /** Create the authenticated user's client profile. */
    public function store(ClientProfileRequest $request)
    {
        // A user can have only one client profile.
        abort_if($request->user()->client, 409, 'Profile already exists.');

        $client = $request->user()->client()->create($request->validated());

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    /** Update the authenticated user's existing client profile. */
    public function update(ClientProfileRequest $request): ClientResource
    {
        $client = $request->user()->client;

        // Stop with HTTP 404 when there is no profile to update.
        abort_unless($client, 404);

        $client->update($request->validated());

        return new ClientResource($client->refresh());
    }
}
