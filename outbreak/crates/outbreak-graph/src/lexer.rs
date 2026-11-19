//! A PHP file's tokens, as far as the graph reads them: names, variables,
//! quoted strings PHP fills nothing into with their text, other strings
//! (literal or not), and single-byte punctuation, with `::`, `->` and `#[`
//! as tokens of their own. Comments and inline HTML are dropped.

/// One token of a PHP file.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Token {
    /// A name, qualified or not, as written: `Foo`, `\Foo\Bar`, `namespace\Foo`.
    Name(String),
    /// A variable, `$` and its name.
    Variable,
    /// A single- or double-quoted string PHP fills nothing into, with the text between its quotes as written.
    Quoted(String),
    /// Any other string: a heredoc or nowdoc, or one PHP fills in; literal where its text holds nothing PHP fills in.
    Str {
        /// Whether the string is literal.
        literal: bool,
    },
    /// A number.
    Number,
    /// `::`.
    DoubleColon,
    /// `->` or `?->`.
    Arrow,
    /// `#[`, an attribute's start.
    Attribute,
    /// Any other byte of punctuation.
    Punct(u8),
}

/// The tokens of a PHP file's text.
#[must_use]
pub fn tokens(text: &[u8]) -> Vec<Token> {
    let mut lexer = Lexer {
        text,
        at: 0,
        out: Vec::new(),
    };
    lexer.html();

    while lexer.at < text.len() {
        lexer.next();
    }

    lexer.out
}

struct Lexer<'a> {
    text: &'a [u8],
    at: usize,
    out: Vec<Token>,
}

impl Lexer<'_> {
    fn peek(&self, ahead: usize) -> u8 {
        self.text.get(self.at + ahead).copied().unwrap_or(0)
    }

    fn starts(&self, word: &[u8]) -> bool {
        self.text
            .get(self.at..self.at + word.len())
            .is_some_and(|slice| slice.eq_ignore_ascii_case(word))
    }

    fn current(&self) -> Option<u8> {
        self.text.get(self.at).copied()
    }

    /// Skips inline HTML up to and past the next open tag.
    fn html(&mut self) {
        let rest = self.text.get(self.at..).unwrap_or_default();
        let Some(open) = rest.windows(2).position(|pair| pair == b"<?") else {
            self.at = self.text.len();
            return;
        };

        self.at += open + 2;

        if self.starts(b"php") {
            self.at += 3;
        } else if self.peek(0) == b'=' {
            self.at += 1;
        }
    }

    fn next(&mut self) {
        let byte = self.peek(0);

        match byte {
            b' ' | b'\t' | b'\r' | b'\n' => self.at += 1,
            b'?' if self.peek(1) == b'>' => {
                self.at += 2;
                self.out.push(Token::Punct(b';'));
                self.html();
            }
            b'#' if self.peek(1) == b'[' => {
                self.at += 2;
                self.out.push(Token::Attribute);
            }
            b'#' => self.line_comment(),
            b'/' if self.peek(1) == b'/' => self.line_comment(),
            b'/' if self.peek(1) == b'*' => self.block_comment(),
            b'\'' | b'"' | b'`' => {
                let start = self.at + 1;
                let literal = self.quoted(byte);
                let text = self
                    .text
                    .get(start..self.at.saturating_sub(1))
                    .unwrap_or_default();

                self.out.push(if literal {
                    Token::Quoted(String::from_utf8_lossy(text).into_owned())
                } else {
                    Token::Str { literal }
                });
            }
            b'<' if self.starts(b"<<<") => self.heredoc(),
            b'$' if is_name_start(self.peek(1)) => {
                self.at += 1;
                self.word();
                self.out.push(Token::Variable);
            }
            b':' if self.peek(1) == b':' => {
                self.at += 2;
                self.out.push(Token::DoubleColon);
            }
            b'-' if self.peek(1) == b'>' => {
                self.at += 2;
                self.out.push(Token::Arrow);
            }
            b'?' if self.peek(1) == b'-' && self.peek(2) == b'>' => {
                self.at += 3;
                self.out.push(Token::Arrow);
            }
            b'0'..=b'9' => {
                while self.peek(0).is_ascii_alphanumeric()
                    || self.peek(0) == b'_'
                    || self.peek(0) == b'.'
                {
                    self.at += 1;
                }
                self.out.push(Token::Number);
            }
            b'\\' if is_name_start(self.peek(1)) => self.name(),
            _ if is_name_start(byte) => self.name(),
            _ => {
                self.at += 1;
                self.out.push(Token::Punct(byte));
            }
        }
    }

    fn word(&mut self) {
        while is_name_byte(self.peek(0)) {
            self.at += 1;
        }
    }

    fn name(&mut self) {
        let start = self.at;

        loop {
            if self.peek(0) == b'\\' {
                self.at += 1;
            }

            self.word();

            if !(self.peek(0) == b'\\' && is_name_start(self.peek(1))) {
                break;
            }
        }

        let text =
            String::from_utf8_lossy(self.text.get(start..self.at).unwrap_or_default()).into_owned();
        self.out.push(Token::Name(text));
    }

    fn line_comment(&mut self) {
        while let Some(byte) = self.current() {
            if byte == b'\n' || (byte == b'?' && self.peek(1) == b'>') {
                return;
            }

            self.at += 1;
        }
    }

    fn block_comment(&mut self) {
        self.at += 2;

        while self.current().is_some() && !self.starts(b"*/") {
            self.at += 1;
        }

        self.at += 2;
    }

    /// Skips a quoted string; whether it is literal: single-quoted, or with no `$` PHP fills in.
    fn quoted(&mut self, quote: u8) -> bool {
        let mut literal = true;
        self.at += 1;

        while let Some(byte) = self.current() {
            if byte == quote {
                break;
            }

            if byte == b'\\' {
                self.at += 1;
            } else if quote != b'\''
                && ((byte == b'$' && (is_name_start(self.peek(1)) || self.peek(1) == b'{'))
                    || (byte == b'{' && self.peek(1) == b'$'))
            {
                literal = false;
            }
            self.at += 1;
        }

        self.at += 1;
        literal && quote != b'`'
    }

    fn heredoc(&mut self) {
        self.at += 3;

        while self.peek(0) == b' ' || self.peek(0) == b'\t' {
            self.at += 1;
        }

        let nowdoc = self.peek(0) == b'\'';
        let quoted = nowdoc || self.peek(0) == b'"';

        if quoted {
            self.at += 1;
        }

        let start = self.at;
        self.word();
        let label = self.text.get(start..self.at).unwrap_or_default().to_vec();

        let mut literal = true;

        while let Some(byte) = self.current() {
            if byte == b'\n' {
                self.at += 1;

                while self.peek(0) == b' ' || self.peek(0) == b'\t' {
                    self.at += 1;
                }

                if !label.is_empty() && self.starts(&label) && !is_name_byte(self.peek(label.len()))
                {
                    self.at += label.len();
                    break;
                }

                continue;
            }

            if !nowdoc && byte == b'$' && is_name_start(self.peek(1)) {
                literal = false;
            }

            self.at += 1;
        }

        self.out.push(Token::Str { literal });
    }
}

fn is_name_start(byte: u8) -> bool {
    byte.is_ascii_alphabetic() || byte == b'_' || byte >= 0x80
}

fn is_name_byte(byte: u8) -> bool {
    is_name_start(byte) || byte.is_ascii_digit()
}

#[cfg(test)]
mod tests {
    use super::Token::{Arrow, Attribute, DoubleColon, Number, Punct, Quoted, Str, Variable};
    use super::{Token, tokens};

    fn name(text: &str) -> Token {
        Token::Name(text.to_owned())
    }

    fn quoted(text: &str) -> Token {
        Quoted(text.to_owned())
    }

    fn names(text: &str) -> Vec<String> {
        tokens(text.as_bytes())
            .into_iter()
            .filter_map(|token| match token {
                Token::Name(name) => Some(name),
                _ => None,
            })
            .collect()
    }

    #[test]
    fn reads_names_and_skips_comments_strings_and_html() {
        let text = "<html><?php // Gone\n/* Gone */ # Gone\nnew \\App\\Money('Gone', \"Gone\");\n?>Gone<?php Foo::bar();";

        assert_eq!(names(text), ["new", "\\App\\Money", "Foo", "bar"]);
    }

    #[test]
    fn tells_literal_strings_from_filled_ones() {
        let found = tokens(b"<?php 'a$b'; \"plain\"; \"$x\"; <<<'N'\n$x\nN;\n<<<H\n$y\nH;\n");
        let strings: Vec<bool> = found
            .into_iter()
            .filter_map(|token| match token {
                Token::Str { literal } => Some(literal),
                Token::Quoted(_) => Some(true),
                _ => None,
            })
            .collect();

        assert_eq!(strings, [true, true, false, true, false]);
        assert!(tokens(b"<?php 'App\\Money';").contains(&Token::Quoted("App\\Money".to_owned())));
    }

    #[test]
    fn reads_attributes_arrows_and_double_colons() {
        let found = tokens(b"<?php #[Covers] $a?->b; A::c;");

        assert!(found.contains(&Token::Attribute));
        assert!(found.contains(&Token::Arrow));
        assert!(found.contains(&Token::DoubleColon));
    }

    #[test]
    fn reads_short_echo_tags_closing_comments_and_indented_heredocs() {
        assert_eq!(names("<?= $a ?>Gone<?php Foo;"), ["Foo"]);
        assert_eq!(names("<?php // Gone ?>Gone<?php Bar;"), ["Bar"]);
        assert_eq!(names("<?php <<< 'N'\n  Gone\n  N;\nBaz;"), ["Baz"]);
    }

    #[test]
    fn starts_right_after_each_open_tag() {
        assert_eq!(tokens(b"x<?phpFoo"), [name("Foo")]);
        assert_eq!(tokens(b"x<?PHP Foo"), [name("Foo")]);
        assert_eq!(tokens(b"x<?=Foo"), [name("Foo")]);
        assert_eq!(tokens(b"x<?Foo"), [name("Foo")]);
        assert_eq!(tokens(b"Foo"), []);
        assert_eq!(
            tokens(b"<?php Foo ?>x<?php Bar"),
            [name("Foo"), Punct(b';'), name("Bar")]
        );
        assert_eq!(tokens(b"x<??><?php Bar"), [Punct(b';'), name("Bar")]);
    }

    #[test]
    fn reads_each_token_of_an_expression() {
        assert_eq!(
            tokens(b"<?php $a = $b / 2 - 1.5 < $c ? Foo::BAR : $d?->e->f; #[A] $$g;"),
            [
                Variable,
                Punct(b'='),
                Variable,
                Punct(b'/'),
                Number,
                Punct(b'-'),
                Number,
                Punct(b'<'),
                Variable,
                Punct(b'?'),
                name("Foo"),
                DoubleColon,
                name("BAR"),
                Punct(b':'),
                Variable,
                Arrow,
                name("e"),
                Arrow,
                name("f"),
                Punct(b';'),
                Attribute,
                name("A"),
                Punct(b']'),
                Punct(b'$'),
                Variable,
                Punct(b';'),
            ]
        );
    }

    #[test]
    fn reads_a_question_mark_before_a_minus_or_a_greater_than_as_a_ternary() {
        assert_eq!(
            tokens(b"<?php $a?1>2:3"),
            [
                Variable,
                Punct(b'?'),
                Number,
                Punct(b'>'),
                Number,
                Punct(b':'),
                Number
            ]
        );
        assert_eq!(
            tokens(b"<?php $a?-1:2"),
            [
                Variable,
                Punct(b'?'),
                Punct(b'-'),
                Number,
                Punct(b':'),
                Number
            ]
        );
    }

    #[test]
    fn reads_a_number_with_its_letters_underscores_and_dots() {
        assert_eq!(tokens(b"<?php 0x1F 1_000 1.5e3"), [Number, Number, Number]);
    }

    #[test]
    fn reads_each_quoted_string_up_to_its_own_closing_quote() {
        assert_eq!(
            tokens(br#"<?php 'a\'b' "c\"d" "a{b" '$a' "a$" "{$e}" "${f}" `g` 'h"#),
            [
                quoted(r"a\'b"),
                quoted(r#"c\"d"#),
                quoted("a{b"),
                quoted("$a"),
                quoted("a$"),
                Str { literal: false },
                Str { literal: false },
                Str { literal: false },
                quoted("h"),
            ]
        );
    }

    #[test]
    fn drops_every_kind_of_comment_up_to_its_end() {
        assert_eq!(
            names("<?php // a?b c>d\nFoo /* x * / */ Bar /*/ Baz */ Qux # z\nQuux"),
            ["Foo", "Bar", "Qux", "Quux"]
        );
        assert_eq!(tokens(b"<?php /* open"), []);
    }

    #[test]
    fn ends_a_heredoc_only_at_its_whole_label() {
        assert_eq!(
            tokens(b"<?php <<<EOT\n  $x\n  EOT;\n<<<\"Q\"\nplain\nQ;\n<<<E\nEX\n$\nE;\nA"),
            [
                Str { literal: false },
                Punct(b';'),
                Str { literal: true },
                Punct(b';'),
                Str { literal: true },
                Punct(b';'),
                name("A"),
            ]
        );
        assert_eq!(tokens(b"<?php <<<\n;Foo"), [Str { literal: true }]);
    }
}
