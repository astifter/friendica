<h3 id="photo-album-title" class="pull-left">{{$album}}</h3>

{{if $can_post}}
<a class="pull-right" href="{{$upload.1}}">
<i class="heading-icon-padding faded-icon-padding faded-icon fa fa-upload" aria-hidden="true"></i><span class="sr-only">{{$upload.0}}</span>
</a>
{{/if}}
{{if $edit}}
<a class="pull-right" href="{{$edit.1}}">
<i class="heading-icon-padding faded-icon-padding faded-icon fa fa-pencil" aria-hidden="true"></i><span class="sr-only">{{$edit.0}}</span>
</a>
{{/if}}
{{if $can_post}}
<a class="pull-right" href="{{$order.1}}">
<i class="heading-icon-padding faded-icon-padding faded-icon fa fa-sort" aria-hidden="true"></i><span class="sr-only">{{$order.0}}</span>
</a>
{{/if}}

<div class="clear"></div>

{{foreach $photos as $photo}}
	{{include file="photo_top.tpl"}}
{{/foreach}}

<div class="photo-album-end"></div>

{{$paginate}}

<script type="text/javascript">$(document).ready(function() { loadingPage = false; justifyPhotos('photo-album-contents-{{$album_id}}'); });</script>
