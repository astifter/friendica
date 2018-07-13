{{if $title}}
	{{if $left == true}}
	<h2 class="pull-left">{{$title}}</h2>
	{{else}}
	<div class="section-title-wrapper">
	<h2>{{$title}}</h2>
	<div class="clear"></div>
	</div>
	{{/if}}
{{/if}}
