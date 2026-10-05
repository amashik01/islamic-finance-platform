<a href="{{ route('business.projects.show', $row) }}" class="btn-secondary btn-sm">View</a>
@can('update', $row)<a href="{{ route('business.projects.edit', $row) }}" class="btn-primary btn-sm">Edit</a>@endcan
