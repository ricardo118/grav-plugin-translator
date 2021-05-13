// FORM HANDLING SECTION
let dirtyForm = false;
let changes = ['template'];
let oldVal = "";

const origin_form = $('#blueprints');
const translate_form = $('#translated');

translate_form.find('input').on("textchange", function() {
    const name = this.name;
    addToChanges(name);
});

translate_form.find('textarea').on("change keyup paste", function() {
    const currentVal = $(this).val();
    const name = this.name;

    if(currentVal === oldVal) {
        return;
    }
    oldVal = currentVal;

    addToChanges(name);

});

function addToChanges(name) {
    if(changes.indexOf(name) === -1) {
        changes.push(name);
        dirtyForm = true;
    }
}

function hidePopUp(type = 'danger') {
    const messages = $('#messages');
    const alert = messages.find('.alert');

    setTimeout(() => {
        messages.addClass('hide');
        alert.removeClass(`alert-${type}`);
    }, 3000);
}

function showPopUp(message, type = 'danger', autohide = false) {
    const messages = $('#messages');
    const alert = messages.find('.alert');

    alert.addClass(`alert-${type}`).text(message);;
    messages.removeClass('hide');

    if (autohide) {
        hidePopUp(type);
    }
}

window.addEventListener('beforeunload', function (e) {
    if (dirtyForm) {
        e.preventDefault();
        e.returnValue = "You have unsaved changes, please confirm to navigate away";
    }
});

translate_form.submit(function(event) {
    const data = $(this).serializeArray();
    const result = data.filter(f => changes.includes(f.name));
    const url = $('[data-translator-save]').attr('href');

    event.preventDefault();
    if (dirtyForm) {
        $.post(url, result, function (data) {

            showPopUp(data.message, data.type);

        }, "json").done(function () {

            $('[data-approval]').removeClass('hide');
            $('[data-preview]').removeClass('hide');
            hidePopUp(data.type);
        });
    } else {
        showPopUp('No changes made! Nothing to save.', true);
    }
});

// SUBMIT FOR APPROVAL BUTTON
$('[data-approval]').on('click',function(e) {
    e.preventDefault();
    const target = $(e.currentTarget);
    const route = target.data('approval');
    const url = $(this).attr('href');

    $.ajax({
        type: 'POST',
        url: url,
        // dataType: 'json',
        data: {'route': route},
        success: function (data) {
            showPopUp(data.message, data.type, true)
        },
    });
});

// Translate Button
$('[data-translate]').on('click',function(e) {
    e.preventDefault();

    const lang = encodeURIComponent($('[data-lang]').val());
    const route = $('[data-page-select]').val();
    let url = $(this).attr('href');
    window.location = `${url}${route}/lang:${lang}`;
});

// Copy to Clipboard
origin_form.find('.form-label').on('click',function(e) {
    const formField = $(this).closest('.form-field');
    const input = formField.find('input');
    const textarea = formField.find('textarea');
    const content = input.val() ? input.val() : textarea.val();

    copyTextToClipboard(content, formField);
});

function fallbackCopyTextToClipboard(text, field) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        let successful = document.execCommand('copy');
        if (successful) {
            field.addClass('copied');
            setTimeout(function () {
                field.removeClass('copied');
            }, 1500);
        }
        let msg = successful ? 'successful' : 'unsuccessful';
        console.log('Fallback: Copying text command was ' + msg);
    } catch (err) {
        console.error('Fallback: Oops, unable to copy', err);
    }

    document.body.removeChild(textArea);
}

function copyTextToClipboard(text, field) {
    if (!navigator.clipboard) {
        fallbackCopyTextToClipboard(text, field);
        return;
    }
    navigator.clipboard.writeText(text).then(function() {
        field.addClass('copied');
        setTimeout(function(){
            field.removeClass('copied');
        }, 1500);
    }, function(err) {
        console.error('Async: Could not copy text: ', err);
    });
}

// Switch Language Button
$('[data-language]').on('change', function() {
    const lang = $(this).val();
    let text = $('[data-language] option:selected').text();
    const link = $('[data-change-language]');
    const linkText = 'Change Language to';
    let url = link.attr('href');

    url = `${url}/lang:${lang}`;

    link.attr('href', url).text(`${linkText} ${text}`).css('opacity', '1')
});


// $( document ).ready(function() {
//
//     $('[data-collection-holder]').each(function() {
//         hideEmptyList($(this));
//     });
//
//     origin_form.find('input, textarea').each(function() {
//         hideEmptyFields($(this));
//     });
//
//     $('.section-wrapper').each(function() {
//         hideEmptyWrappers($(this));
//     });
// });

function hideEmptyWrappers(element) {
    if (element.find('.block, .form-field').length === 0) {
        element.remove();
    }
}

function hideEmptyFields(element) {

    if (!element.val()) {
        const field_name = element.attr('name');
        translate_form.find(`[name="${field_name}"]`).closest('.block').remove();
        element.closest('.block').remove();
    }
}

function hideEmptyList(element) {

    if (element.children().length === 0) {
        element.closest('.form-field').addClass('d-none');
    }
}

$(function() {
    const tenMinuteInterval = 600000;
    // const storiesInterval = 10 * 1000;

    const keepAlive = function() {
        console.log('Sending Keep Alive request...');
        $.ajax({
            type: "POST",
            url: "/translator/edit/task:translator.keep.alive",
        }).done(function(msg) {
            console.log('success');
        }).fail(function() {
            console.log('error');
        }).always(function() {
            // Schedule the next request after this one completes,
            // even after error
            console.log('Waiting ' + (tenMinuteInterval / 1000) + ' seconds');
            setTimeout(keepAlive, tenMinuteInterval);
        });
    };

    // Fetch news immediately, then every 10 seconds AFTER previous request finishes
    keepAlive();
});

// Google Translate button
$('[data-g-translate]').on('click',function(e) {
    e.preventDefault();
    const target = $(e.currentTarget);
    const url = $(this).attr('href');
    const serializedForm = $('#blueprints').serializeArray();

    $('#overlay').fadeIn();
    $.ajax({
        type: 'POST',
        url: url,
        // dataType: 'json',
        data: {serializedForm},
        success: function (data) {
            updateTranslations(data);
        },
        error: function (xhr) {
            console.log(xhr.status);
            const json = JSON.parse(xhr.responseText);
            let message = json.error.message;

            try {
                message = JSON.parse(message);
                console.log(message);
                $('#overlay h1').text('Error occurred: ' + message.error.message);
            } catch (e) {
                $('#overlay h1').text('Error occurred');
            }

            setTimeout(function(){ $('#overlay').fadeOut(); }, 2000);
        }
    });
});

function updateTranslations(data) {
    const form = $('#translated');

    for(let i = 0; i < data.length; i++) {
        form.find('[name="' + data[i].name + '"]').val(data[i].value);
        addToChanges(data[i].name);
    }

    form.submit();
    dirtyForm = false;
    setTimeout(function(){ window.location.reload(true); }, 2000);

    $('#overlay').fadeOut();
}
