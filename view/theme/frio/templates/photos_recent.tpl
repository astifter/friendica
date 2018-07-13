<h3 class="pull-left">{{$title}}</h3>

{{if $can_post}}
	<a class="pull-right" href="{{$upload.1}}">
	<i class="heading-icon-padding faded-icon-padding faded-icon fa fa-upload" aria-hidden="true"></i><span class="sr-only">{{$upload.0}}</span>
	</a>
{{/if}}

<div id="photo-album-contents" class="photos">
{{foreach $photos as $photo}}
	{{include file="photo_top.tpl"}}
{{/foreach}}
</div>
<div class="photos-end"></div>

{{$paginate}}

<script type="text/javascript">$(document).ready(function() { loadingPage = false; justifyPhotos(); });</script>
