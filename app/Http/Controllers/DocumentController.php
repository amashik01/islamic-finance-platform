<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Document\DocumentService;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentService $documents)
    {
        $this->authorize('view', $document);

        return $documents->respond($document, $request->boolean('inline'));
    }
}
