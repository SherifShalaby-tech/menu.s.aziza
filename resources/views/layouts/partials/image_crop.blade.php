<div class="image_area">
    <label for="upload_image">
        <img src="@if(!empty($image_url)){{$image_url}}@endif" id="uploaded_image" class="" />
        <input type="hidden" name="uploaded_image_name" id="uploaded_image_name" value="">
        <input type="file" name="image" class="image" id="upload_image" style="display:none" />
    </label>
</div>
