<div id="sidebar-photos-albums" class="widget">
	<h3 class="pull-left">{{$title}}</h3>
	{{if $can_post}}
	<a class="pull-right" href="{{$upload.1}}" data-toggle="tooltip" title="{{$upload.0}}">
	<i class="faded-icon fa fa-upload" aria-hidden="true"></i>
	</a>
	{{/if}}

	<ul role="menubar" class="sidebar-photos-albums-ul clear">
		<li role="menuitem" class="sidebar-photos-albums-li">
			<a href="{{$baseurl}}/photos/{{$nick}}" class="sidebar-photos-albums-element" title="{{$title}}" >{{$recent}}</a>
		</li>

		{{if $albums}}
		{{foreach $albums as $al}}
		{{if $al.text}}
		<li role="menuitem" class="sidebar-photos-albums-li">
			<a href="{{$baseurl}}/photos/{{$nick}}/album/{{$al.bin2hex}}" class="sidebar-photos-albums-element">
				<span class="badge pull-right">{{$al.total}}</span>{{$al.text}}
			</a>
		</li>
		{{/if}}
		{{/foreach}}
		{{/if}}
	</ul>
</div>
