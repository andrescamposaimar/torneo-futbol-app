(function () {
    tinymce.create('tinymce.plugins.Shortcodes', {
        init: function (ed, url) {

            ed.addButton('shortcodes', {
                text: 'Shortcodes',
                title: 'Short codes',
                type: 'menubutton',
                menu: [
                    {
                        text: 'Custom lists',
                        menu: [
                            {
                                text: 'Check',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'check');
                                }
                            },
                            {
                                text: 'Check Circle',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'check circle');
                                }
                            },
                            {
                                text: 'Angle',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'angle');
                                }
                            },
                            {
                                text: 'Angle Circle',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'angle circle');
                                }
                            },
                            {
                                text: 'Asterisk',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'asterisk');
                                }
                            },
                            {
                                text: 'Asterisk Circle',
                                onclick: function () {
                                    ed.execCommand('InsertUnorderedList', 0);
                                    ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'asterisk circle');
                                }
                            }
                        ]
                    },
                    {
                        text: 'Buttons',
                        menu: [
                            {
                                text: 'Small',
                                menu: [
                                    {
                                        text: 'Default',
                                        menu: [
                                            {
                                                text: 'Black',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary no-grd btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Black Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger no-grd btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Black Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Red Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info red btn-sm">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            }
                                        ]
                                    },
                                    {
                                        text: 'Socials',
                                        menu: [
                                            {
                                                text: 'Facebook',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-facebook-square"></i><b>Facebook</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Twitter',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-twitter-square"></i><b>Twitter</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Linkedin',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-linkedin-square"></i><b>Linkedin</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Instagram',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-instagram"></i><b>Instagram</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Google+',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-google-plus-square"></i><b>Google+</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Vimeo',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-vimeo-square"></i><b>Vimeo</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Pinterest',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-pinterest-square"></i><b>Pinterest</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Dribbble',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-dribbble"></i><b>Dribbble</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Rss',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-rss-square"></i><b>Rss</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Youtube',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-youtube-square"></i><b>Youtube</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Dropbox',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button mini"><i class="fa fa-dropbox"></i><b>Dropbox</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            }
                                        ]
                                    },
                                ]
                            },
                            {
                                text: 'Medium',
                                menu: [
                                    {
                                        text: 'Default',
                                        menu: [
                                            {
                                                text: 'Black',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary no-grd">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Black Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger no-grd">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Black Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Red Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info red">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            }
                                        ]
                                    },
                                    {
                                        text: 'Socials',
                                        menu: [
                                            {
                                                text: 'Facebook',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button facebook"><i class="fa fa-facebook"></i><b>Facebook</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Twitter',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button twitter"><i class="fa fa-twitter"></i><b>Twitter</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Linkedin',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button linkedin"><i class="fa fa-linkedin"></i><b>Linkedin</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Instagram',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button instagram"><i class="fa fa-instagram"></i><b>Instagram</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Google+',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button google"><i class="fa fa-google-plus"></i><b>Google+</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Pinterest',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button pinterest"><i class="fa fa-pinterest"></i><b>Pinterest</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Dribbble',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button dribbble"><i class="fa fa-dribbble"></i><b>Dribbble</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Skype',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button skype"><i class="fa fa-skype"></i><b>Skype</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Rss',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button rss"><i class="fa fa-rss"></i><b>Rss</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Youtube',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="social_button youtube"><i class="fa fa-youtube"></i><b>Youtube</b></a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            }
                                        ]
                                    }
                                ]
                            },
                            {
                                text: 'Large',
                                menu: [
                                    {
                                        text: 'Default',
                                        menu: [
                                            {
                                                text: 'Black',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary no-grd btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Black Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-primary btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger no-grd btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'Red Gradient',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-danger btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Black Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            },
                                            {
                                                text: 'With Red Border',
                                                onclick: function () {
                                                    return_text = '<a href="#" class="btn btn-info red btn-lg">Button</a>';
                                                    ed.execCommand('mceReplaceContent', false, return_text);
                                                }
                                            }
                                        ]
                                    }
                                ]
                            }
                        ]
                    },
                    {
                        text: 'Custom quote',
                        onclick: function () {
                            var selected_text = ed.selection.getContent();
                            var return_text = '';
                            return_text = '<blockquote class="quote">' + selected_text + '</blockquote>';
                            ed.execCommand('mceInsertContent', 0, return_text);
                        }
                    },
                    {
                        text: 'Messages',
                        menu: [
                            {
                                text: 'Info', onclick: function () {
                                ed.execCommand('putMessage', 'alert-info');
                            }
                            },
                            {
                                text: 'Danger', onclick: function () {
                                ed.execCommand('putMessage', 'alert-danger');
                            }
                            },
                            {
                                text: 'Warning', onclick: function () {
                                ed.execCommand('putMessage', 'alert-warning');
                            }
                            },
                            {
                                text: 'Success', onclick: function () {
                                ed.execCommand('putMessage', 'alert-success');
                            }
                            }
                        ]
                    },
                    {
                        text: 'Columns',
                        menu: [
                            {
                                text: '1/2', onclick: function () {
                                ed.execCommand('putColumns', 'one_half');
                            }
                            },
                            {
                                text: '1/3', onclick: function () {
                                ed.execCommand('putColumns', 'one_third');
                            }
                            },
                            {
                                text: '1/6', onclick: function () {
                                ed.execCommand('putColumns', 'one_six');
                            }
                            },
                            {
                                text: '2/3', onclick: function () {
                                ed.execCommand('putColumns', 'two_third');
                            }
                            }
                        ]
                    }


                ]
            });


            ed.addCommand('putMessage', function (type) {

                var selected_text = ed.selection.getContent();
                var return_text = '';
                if (type == 'alert-danger') {
                    selected_text = '<i class="fa fa-exclamation-triangle"></i>' + selected_text;
                } else if (type == 'alert-warning') {
                    selected_text = '<i class="fa fa-thumbs-up"></i>' + selected_text;
                } else if (type == 'alert-success') {
                    selected_text = '<i class="fa fa-check-circle"></i>' + selected_text;
                } else {
                    selected_text = '<i class="fa fa-info-circle"></i>' + selected_text;
                }
                return_text = '<div class="alert alert-dismissible ' + type + '"><button type="button" class="close" data-dismiss="alert"><span aria-hidden="true"><i class="fa fa-times"></i></span><span class="sr-only">Close</span></button>' + selected_text + '</div>';
                ed.execCommand('mceInsertContent', 0, return_text);

            });

            ed.addCommand('putColumns', function (type) {

                var return_text = '';
                switch (type) {
                    case 'one_half':
                        return_text = '<div class="row"><div class="col-xs-12 col-md-6 col-sm-6">...</div><div class="col-xs-12 col-md-6 col-sm-6 last">...</div></div>';
                        break;
                    case 'one_third':
                        return_text = '<div class="row"><div class="col-xs-12 col-md-4 col-sm-4">...</div><div class="col-xs-12 col-md-4 col-sm-4 last">...</div><div class="col-xs-12 col-md-4 col-sm-4 last">...</div></div>';
                        break;
                    case 'one_six':
                        return_text = '<div class="row"><div class="col-xs-12 col-md-2 col-sm-2 last">...</div><div class="col-xs-12 col-md-2 col-sm-2 last">...</div><div class="col-xs-12 col-md-2 col-sm-2 last">...</div><div class="col-xs-12 col-md-2 col-sm-2 last">...</div><div class="col-xs-12 col-md-2 col-sm-2 last">...</div><div class="col-xs-12 col-md-2 col-sm-2 last">...</div></div>';
                        break;
                    case 'two_third':
                        return_text = '<div class="row"><div class="col-xs-12 col-md-8 col-sm-8">...</div><div class="col-xs-12 col-md-4 col-sm-4 last">...</div></div>';
                        break;


                }

                ed.execCommand('mceInsertContent', 0, return_text);

            });

            ed.addCommand('dropcap', function () {


                ed.execCommand('InsertUnorderedList', 0);
                ed.dom.addClass(ed.dom.getParent(ed.selection.getNode(), 'ul'), 'custom_list');


            });


        }


    });
    // Register plugin
    tinymce.PluginManager.add('stm', tinymce.plugins.Shortcodes);
})();