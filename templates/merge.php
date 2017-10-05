<div id="merge" class="attached input" data-toggle="tooltip" data-placement="bottom" title="{MERGING_TOOLTIP}" style="display: inline-block;height: 26px;text-align:left">
    <select id="masterid" name="masterid" style="width: 250px" class="js-example-basic-single"><option>{MERGING_PLACEHOLDER}</option>{MERGING_OPTIONS}</select>
    <button id="mergebutton" type="submit" class="attached button"><i class="icon-code-fork"></i>
    </button>
</div>
<button id="splitbutton" class="button"><i class="icon-code-fork"></i>
</button>
<script>
    $(document).ready(function(){
        $("#masterid").select2({});
        $("#masterid").val('');
        $("#mergebutton").click(function(){
            var $form = $('form#tickets');
            var count = checkbox_checker($form, 1);
            if (count) {
                var tids = $('.ckb:checked', $form).map(function() {
                    return this.value;
                }).get();
                $.ajax({
                    type: 'POST',
                    url: '../include/plugins/Merging/ajax.php',
                    data: ({
                        master: $("#masterid").val(),
                        ticket: tids,
                        a: 'merge'
                    }),
                    success: function(data) {
                        window.location.href=window.location.href;
                    }
                });
            }
        });
        $("#splitbutton").click(function(){
            var $form = $('form#tickets');
            var count = checkbox_checker($form, 1);
            if (count) {
                var tids = $('.ckb:checked', $form).map(function() {
                    return this.value;
                }).get();
                $.ajax({
                    type: 'POST',
                    url: '../include/plugins/Merging/ajax.php',
                    data: ({
                        tickets: tids,
                        a: 'masssplit'
                    }),
                    success: function(data) {
                        window.location.href=window.location.href;
                    }
                });
            }
        });
    });
</script>
<style>
    div#merge .select2-selection--single, div#merge .select2-selection__rendered {
        height: 26px;
        border-right: none;
        border-top-right-radius: 0px;
        border-bottom-right-radius: 0px;
    }
    div#merge .select2-container{
        margin-right: -1px;
    }
</style>